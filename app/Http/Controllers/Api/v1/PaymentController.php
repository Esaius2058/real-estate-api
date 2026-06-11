<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\DarajaService;
use App\Services\PaystackService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    protected DarajaService $darajaService;
    protected PaystackService $paystackService;

    public function __construct(DarajaService $darajaService, PaystackService $paystackService)
    {
        $this->darajaService = $darajaService;
        $this->paystackService = $paystackService;
    }

    // ------------------------------------------------------------
    //  M‑PESA METHODS (existing, unchanged)
    // ------------------------------------------------------------
    public function stkPush(Request $request) { /* ... existing code ... */ }
    public function checkStatus($checkoutRequestID) { /* ... existing code ... */ }
    public function callback(Request $request) { /* ... existing code ... */ }
    public function getUserPayments(Request $request) { /* ... existing code ... */ }

    // ------------------------------------------------------------
    //  NEW PAYSTACK METHODS (card payments for escrow & subscriptions)
    // ------------------------------------------------------------

    /**
     * Initialize a Paystack transaction for an escrow payment.
     * POST /api/v1/paystack/initialize
     */
/**
     * Initialize a Paystack transaction for an escrow payment.
     * POST /api/v1/paystack/initialize
     */
    public function initializePaystack(Request $request)
    {
        $request->validate([
            'amount'     => 'required|numeric|min:1',
            'escrow_id'  => 'required|exists:escrows,id',
        ]);

        try {
            $escrow = \App\Models\Escrow::findOrFail($request->escrow_id);
            $user = $request->user();

            // ✅ FIX: Paystack requires amounts in minor units (e.g., cents/kobo).
            // Convert major currency units (e.g., 100.00 KES) to minor units (10000).
            $amountInMinorUnits = intval($request->amount * 100);

            $result = $this->paystackService->initializeTransaction(
                $user->email,
                $amountInMinorUnits, // Use the converted integer
                ['escrow_id' => $escrow->id],
                null // uses default callback from config
            );

            // Verify both the structure and presence of the initialization URL
            if ($result && isset($result['data']['authorization_url'])) {
                return response()->json([
                    'authorization_url' => $result['data']['authorization_url'],
                    'reference'         => $result['data']['reference'],
                ]);
            }

            // Log anomalies for quick troubleshooting
            Log::error('Paystack Service did not return URL payload:', ['response' => $result]);
            return response()->json(['message' => 'Payment initialization failed at provider gateway.'], 400);

        } catch (\Exception $e) {
            Log::error('Paystack initialization exception: ' . $e->getMessage());
            return response()->json(['message' => 'Internal server error processing payment request.'], 500);
        }
    }
    /**
     * Paystack callback (after user pays on Paystack page).
     * GET /api/v1/paystack/callback
     */
    public function paystackCallback(Request $request)
    {
        $reference = $request->query('reference');
        if (!$reference) {
            return redirect()->to(env('FRONTEND_URL') . '/dashboard?payment=failed');
        }

        $verification = $this->paystackService->verifyTransaction($reference);
        if ($verification && $verification['data']['status'] === 'success') {
            $metadata = $verification['data']['metadata'];
            $escrowId = $metadata['escrow_id'] ?? null;

            DB::transaction(function () use ($verification, $escrowId) {
                Payment::create([
                    'escrow_id'      => $escrowId,
                    'amount'         => $verification['data']['amount'] / 100,
                    'reference'      => $verification['data']['reference'],
                    'status'         => 'completed',
                    'payment_method' => 'paystack_card',
                    'paid_at'        => now(),
                ]);

                if ($escrowId) {
                    $this->updateEscrowProgress($escrowId);
                }
            });

            return redirect()->to(env('FRONTEND_URL') . "/escrow/{$escrowId}?payment=success");
        }

        return redirect()->to(env('FRONTEND_URL') . '/dashboard?payment=failed');
    }

    /**
     * Paystack webhook (real‑time status updates).
     * POST /api/v1/paystack/webhook
     */
    public function paystackWebhook(Request $request)
    {
        // Pass the request directly to utilize raw string comparisons safely
        if (!$this->verifyPaystackSignature($request)) {
            Log::error('Paystack webhook signature invalid');
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $event = $request->json('event');
        if ($event === 'charge.success') {
            $data = $request->json('data');
            $metadata = $data['metadata'];
            $escrowId = $metadata['escrow_id'] ?? null;

            DB::transaction(function () use ($data, $escrowId) {
                Payment::updateOrCreate(
                    ['reference' => $data['reference']],
                    [
                        'escrow_id'      => $escrowId,
                        'amount'         => $data['amount'] / 100,
                        'status'         => 'completed',
                        'payment_method' => 'paystack_card',
                        'paid_at'        => now(),
                    ]
                );
                if ($escrowId) {
                    $this->updateEscrowProgress($escrowId);
                }
            });
        }

        return response()->json(['message' => 'Webhook processed'], 200);
    }

    // ------------------------------------------------------------
    //  HELPER METHODS
    // ------------------------------------------------------------
    private function updateEscrowProgress($escrowId)
    {
        $totalPaid = Payment::where('escrow_id', $escrowId)
                    ->where('status', 'completed')
                    ->sum('amount');
                    
        $escrow = \App\Models\Escrow::find($escrowId);
        if ($escrow) {
            $escrow->total_paid = $totalPaid;
            $escrow->remaining = $escrow->amount - $totalPaid;
            $escrow->is_fully_funded = ($totalPaid >= $escrow->amount);
            $escrow->save();
        }
    }

    /**
     * Verify incoming Webhook signatures natively using raw contents
     */
private function verifyPaystackSignature(Request $request)
{
    $signature = $request->header('x-paystack-signature');
    $secret = config('paystack.secret_key');   // ✅ correct path
    if (!$secret || !$signature) {
        return false;
    }
    $computed = hash_hmac('sha512', $request->getContent(), $secret);
    return hash_equals($signature, $computed);
}}