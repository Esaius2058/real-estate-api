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

    public function __construct(DarajaService $darajaService)
    {
        $this->darajaService = $darajaService;
    }

    /**
     * Trigger M-Pesa STK Push from React Frontend
     * Route: POST /api/v1/payments/stk-push
     */
    public function stkPush(Request $request)
    {
        $request->validate([
            'property_id'  => 'required',
            'phone_number' => 'required|string',
        ]);

        $property = Property::findOrFail($request->property_id);
        
        // Testing fallback amount safeguard to avoid real premium billing in sandbox
        $amount = 1; 
        $accountReference = 'MAKAO-' . $property->id;

        try {
            DB::beginTransaction();

            // 1. Dispatch Safaricom API Request Payload via your service layer
            $darajaResponse = $this->darajaService->stkPush(
                $request->phone_number,
                $amount,
                $accountReference
            );

            // 3. Persist Pending Transaction Matrix Record
            $payment = Payment::create([
                'agency_id'           => $property->agency_id ?? 1, 
                'user_id'             => Auth::user()?->id ?? 1,  
                'property_id'         => $property->id,
                'amount'              => $amount,
                'merchant_request_id' => $darajaResponse['MerchantRequestID'] ?? null,
                'checkout_request_id' => $darajaResponse['CheckoutRequestID'] ?? null,
                'status'              => 'pending',
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
     * Route: GET /api/v1/payments/status/{checkoutRequestID}
     */
    public function checkStatus($checkoutRequestID)
    {
        $payment = Payment::where('checkout_request_id', $checkoutRequestID)->first();

        if (!$payment) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction tracking identifier not found.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'status' => $payment->status,
            'receipt_number' => $payment->receipt_number
        ], 200);
    }

    /**
     * Safaricom Webhook Callback Handler
     * Route: POST /api/v1/payments/callback (Ensure this is public in bootstrap/app.php or VerifyCsrfToken)
     */
    public function callback(Request $request)
    {
        Log::info('Incoming M-Pesa Callback Matrix Payload Received', $request->all());

        $callbackData = $request->json('Body.stkCallback');
        $resultCode   = $callbackData['ResultCode'] ?? null;
        $checkoutId   = $callbackData['CheckoutRequestID'] ?? null;

        $payment = Payment::where('checkout_request_id', $checkoutId)->first();

        if (!$payment) {
            Log::warning('M-Pesa Callback received for untracked checkout ID: ' . $checkoutId);
            return response()->json(['status' => 'untracked'], 404);
        }

        try {
            DB::beginTransaction();

            if ($resultCode == 0) {
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

                if ($payment->property) {
                    $payment->property->update(['status' => 'under_contract']);
                }

                Log::info("Payment Successful for Checkout ID: {$checkoutId}. Receipt: {$receiptNumber}");
            } else {
                // Catches user cancellations (ResultCode 1032) or execution timeouts seamlessly
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