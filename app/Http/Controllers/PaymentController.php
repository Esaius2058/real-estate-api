<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\Property;
use App\Services\DarajaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    protected DarajaService $darajaService;

    // Inject the service you will build next
    public function __construct(DarajaService $darajaService)
    {
        $this->darajaService = $darajaService;
    }

    /**
     * Triggered by the React Frontend to start the M-Pesa prompt.
     */
    public function initiate(Request $request)
    {
        $request->validate([
            'property_id' => 'required|exists:properties,id',
            'phone_number' => 'required|string', // Should ideally be formatted to 2547XXXXXXXX
        ]);

        $property = Property::findOrFail($request->property_id);

        // 1. Business Logic: Prevent double booking
        if ($property->status !== 'Available') {
            return response()->json(['message' => 'Property is no longer available.'], 422);
        }

        // 2. Create the Pending Ledger Entry
        // Assuming your properties have a 'price' or 'booking_fee' column. 
        $amount = $property->price; 

        $payment = Payment::create([
            'tenant_id' => auth()->user()->tenant_id ?? 1, // Adjust based on your auth structure
            'user_id' => auth()->id(),
            'property_id' => $property->id,
            'amount' => $amount,
            'status' => 'pending',
        ]);

        // 3. Trigger Safaricom STK Push
        try {
            // Convert to integer (Safaricom rejects decimals) and format reference
            $response = $this->darajaService->stkPush(
                $request->phone_number, 
                (int) $amount, 
                "PROP-{$property->id}"
            );

            // 4. Update ledger with tracking IDs
            if (isset($response['ResponseCode']) && $response['ResponseCode'] == "0") {
                $payment->update([
                    'merchant_request_id' => $response['MerchantRequestID'],
                    'checkout_request_id' => $response['CheckoutRequestID'],
                ]);

                return response()->json([
                    'message' => 'Payment initiated. Check your phone for the M-Pesa prompt.',
                    'checkout_request_id' => $response['CheckoutRequestID']
                ], 200);
            }

            return response()->json(['message' => 'Safaricom rejected the request', 'error' => $response], 400);

        } catch (\Exception $e) {
            Log::error('Daraja STK Push Failed: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to connect to payment gateway.'], 500);
        }
    }

    /**
     * Triggered by Safaricom when the user enters their PIN or cancels.
     */
    public function callback(Request $request)
    {
        // ALWAYS log the raw payload. This saves you hours of debugging.
        Log::info('Daraja Webhook Received:', $request->all());

        $callbackData = $request->input('Body.stkCallback');

        if (!$callbackData) {
            Log::error('Invalid Daraja Payload');
            return response()->json(['message' => 'Invalid payload'], 400);
        }

        $resultCode = $callbackData['ResultCode'];
        $checkoutRequestId = $callbackData['CheckoutRequestID'];

        // Find the pending payment
        $payment = Payment::where('checkout_request_id', $checkoutRequestId)->first();

        if (!$payment) {
            Log::error("Payment not found for CheckoutRequestID: {$checkoutRequestId}");
            // Return 200 anyway so Safaricom stops retrying the webhook
            return response()->json(['message' => 'Acknowledged'], 200); 
        }

        // ResultCode 0 means the user entered their PIN and had sufficient funds.
        if ($resultCode == 0) {
            
            // Safaricom sends metadata in a convoluted array. We need to extract the Receipt Number.
            $metadata = $callbackData['CallbackMetadata']['Item'] ?? [];
            $receiptNumber = null;

            foreach ($metadata as $item) {
                if ($item['Name'] === 'MpesaReceiptNumber') {
                    $receiptNumber = $item['Value'];
                    break;
                }
            }

            // Wrap in a DB transaction so money and property state stay in sync
            DB::transaction(function () use ($payment, $receiptNumber) {
                $payment->update([
                    'status' => 'completed',
                    'receipt_number' => $receiptNumber
                ]);

                $payment->property->update([
                    'status' => 'Sold' // Or 'Under Offer'
                ]);
            });

            Log::info("Payment {$receiptNumber} completed successfully for Property {$payment->property_id}");

        } else {
            // ResultCode != 0 means cancelled, insufficient funds, or timeout.
            $payment->update(['status' => 'failed']);
            Log::info("Payment Failed: " . $callbackData['ResultDesc']);
        }

        // You MUST return a 200 OK, otherwise Daraja will assume your server is down and spam you.
        return response()->json([
            'ResultCode' => 0,
            'ResultDesc' => 'Accepted'
        ]);
    }
}