<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Payment;      
use App\Models\Property;     
use App\Services\DarajaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class PaymentController extends Controller
{
    protected DarajaService $darajaService;

    // Inject DarajaService into the controller
    public function __construct(DarajaService $darajaService)
    {
        $this->darajaService = $darajaService;
    }

    /**
     * Trigger M-Pesa STK Push from React Frontend
     * Renamed from 'initiate' to 'stkPush' to match route: /api/v1/payments/stk-push
     */
    public function stkPush(Request $request)
    {
        $request->validate([
            'property_id'  => 'required',
            'phone_number' => 'required|string',
        ]);

        // Find the property to bill the correct amount
        $property = Property::findOrFail($request->property_id);
        
        // For testing purposes, you can use a fixed amount like 1 KES 
        // to avoid charging KES 85,000,000 on the sandbox toolkit!
        $amount = 1; 
        
        $accountReference = 'MAKAO-' . $property->id;

        try {
            DB::beginTransaction();

            // 1. Trigger Safaricom STK Push Request
            $darajaResponse = $this->darajaService->stkPush(
                $request->phone_number,
                $amount,
                $accountReference
            );

            // 2. Log payment record into your database tracking state
            $payment = Payment::create([
                'agency_id'           => $property->agency_id ?? 1, // Enforce multi-tenancy bounds
                'user_id'             => Auth::user()?->id ?? 1,  // Fallback to seeded admin user if testing
                'property_id'         => $property->id,
                'amount'              => $amount,
                'merchant_request_id' => $darajaResponse['MerchantRequestID'] ?? null,
                'checkout_request_id' => $darajaResponse['CheckoutRequestID'] ?? null,
                'status'              => 'pending',
                'tenant_id'           => $property->tenant_id ?? Auth::user()?->tenant_id ?? 1,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'STK Push initiated successfully.',
                'payment' => $payment,
                'daraja'  => $darajaResponse
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('M-Pesa STK Push Initiation Failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process checkout request: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Real-time Payment Status Checker for Frontend Polling
     * Matches route: GET /api/v1/payments/status/{checkoutRequestID}
     */
    public function checkStatus($checkoutRequestID)
    {
        // Query database for updated state pushed by the callback webhook
        $payment = Payment::where('checkout_request_id', $checkoutRequestID)->first();

        if (!$payment) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction tracking identifier not found.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'status' => $payment->status, // Returns: 'pending', 'completed', or 'failed'
            'receipt_number' => $payment->receipt_number
        ], 200);
    }

    /**
     * Safaricom Webhook Callback Handler
     */
    public function callback(Request $request)
    {
        Log::info('Incoming M-Pesa Callback Matrix Payload Received', $request->all());

        $callbackData = $request->json('Body.stkCallback');
        $resultCode   = $callbackData['ResultCode'] ?? null;
        $checkoutId   = $callbackData['CheckoutRequestID'] ?? null;

        // Find matching pending payment record
        $payment = Payment::where('checkout_request_id', $checkoutId)->first();

        if (!$payment) {
            Log::warning('M-Pesa Callback received for untracked checkout ID: ' . $checkoutId);
            return response()->json(['status' => 'untracked'], 404);
        }

        try {
            DB::beginTransaction();

            if ($resultCode == 0) {
                // Success path
                $callbackItems = $callbackData['CallbackMetadata']['Item'] ?? [];
                $receiptNumber = null;

                foreach ($callbackItems as $item) {
                    if ($item['Name'] === 'MpesaReceiptNumber') {
                        $receiptNumber = $item['Value'];
                        break;
                    }
                }

                $payment->update([
                    'receipt_number' => $receiptNumber,
                    'status'         => 'completed',
                ]);

                // Update property structural state to under contract/sold automatically
                if ($payment->property) {
                    $payment->property->update(['status' => 'under_contract']);
                }

                Log::info("Payment Successful for Checkout ID: {$checkoutId}. Receipt: {$receiptNumber}");
            } else {
                // Cancelled or Failed path (e.g., User cancelled, Insufficient funds)
                $payment->update(['status' => 'failed']);
                Log::notice("Payment Cancelled/Failed for Checkout ID: {$checkoutId}. Code: {$resultCode}");
            }

            DB::commit();
            return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted successfully']);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error processing Daraja callback handling block: ' . $e->getMessage());
            return response()->json(['ResultCode' => 1, 'ResultDesc' => 'Internal server error'], 500);
        }
    }
}