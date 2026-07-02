<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Escrow;
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

    // ─── M-PESA ───────────────────────────────────────────────────────────────

    /**
     * Trigger STK Push to customer phone
     * POST /api/v1/payments/stk-push
     */
    public function stkPush(Request $request)
    {
        $request->validate([
            'phone'  => 'required|string',
            'amount' => 'required|numeric|min:1',
        ]);

        try {
            $result = $this->darajaService->stkPush(
                $request->phone,
                $request->amount,
                $request->escrow_id ?? null
            );

            $payment = Payment::create([
                'user_id'              => $request->user()->id,
                'escrow_id'            => $request->escrow_id ?? null,
                'amount'               => $request->amount,
                'checkout_request_id'  => $result['CheckoutRequestID'] ?? null,
                'status'               => 'pending',
                'payment_method'       => 'mpesa',
            ]);

            return response()->json([
                'success'  => true,
                'message'  => 'STK Push sent successfully.',
                'payment'  => $payment,
            ]);
        } catch (\Exception $e) {
            Log::error('STK Push failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Safaricom Daraja Callback
     * POST /api/v1/payments/callback
     */
    public function callback(Request $request)
    {
        Log::info('M-Pesa Callback received', $request->all());

        $callbackData = $request->json('Body.stkCallback');
        $resultCode   = $callbackData['ResultCode'] ?? null;
        $checkoutId   = $callbackData['CheckoutRequestID'] ?? null;

        $payment = Payment::where('checkout_request_id', $checkoutId)->first();

        if (!$payment) {
            Log::warning('M-Pesa callback for untracked checkout ID: ' . $checkoutId);
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

                if ($payment->escrow_id) {
                    $this->updateEscrowProgress($payment->escrow_id);
                }
            } else {
                $payment->update(['status' => 'failed']);
            }

            DB::commit();
            return response()->json(['status' => 'ok']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('M-Pesa callback processing failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Callback processing failed.'], 500);
        }
    }

    /**
     * Poll payment status
     * GET /api/v1/payments/status/{checkoutRequestID}
     */
    public function checkStatus($checkoutRequestID)
    {
        $payment = Payment::where('checkout_request_id', $checkoutRequestID)->first();

        if (!$payment) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction not found.',
            ], 404);
        }

        return response()->json([
            'success'        => true,
            'status'         => $payment->status,
            'receipt_number' => $payment->receipt_number,
        ]);
    }

    /**
     * Get user payment history
     * GET /api/v1/payments/history
     */
    public function history(Request $request)
    {
        $payments = Payment::where('user_id', $request->user()->id)
            ->latest()
            ->paginate(15);

        return response()->json($payments);
    }

    // ─── PAYSTACK ─────────────────────────────────────────────────────────────

    /**
     * Initialize Paystack transaction for escrow payment
     * POST /api/v1/payments/paystack/initialize
     */
    public function initializePaystack(Request $request)
    {
        $request->validate([
            'amount'    => 'required|numeric|min:1',
            'escrow_id' => 'required|exists:escrows,id',
        ]);

        try {
            $escrow = Escrow::findOrFail($request->escrow_id);
            $user   = $request->user();

            $amountInMinorUnits = intval(round($request->amount * 100));

            $result = $this->paystackService->initializeTransaction(
                $user->email,
                $amountInMinorUnits,
                ['escrow_id' => $escrow->id],
                null
            );

            if ($result && isset($result['data']['authorization_url'])) {
                $payment = Payment::create([
                    'escrow_id'      => $escrow->id,
                    'user_id'        => $user->id,
                    'amount'         => $request->amount,
                    'reference'      => $result['data']['reference'] ?? null,
                    'status'         => 'pending',
                    'payment_method' => 'paystack_card',
                ]);

                return response()->json([
                    'success'           => true,
                    'authorization_url' => $result['data']['authorization_url'],
                    'reference'         => $result['data']['reference'] ?? null,
                    'payment'           => $payment,
                ]);
            }

            Log::error('Paystack initialization failed', ['response' => $result]);
            return response()->json(['success' => false, 'message' => 'Failed to initialize Paystack transaction.'], 500);

        } catch (\Exception $e) {
            Log::error('Paystack initialization exception', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Paystack redirect callback after payment
     * GET /api/v1/paystack/callback
     */
    public function verifyPaystack(Request $request)
    {
        $reference = $request->query('reference');

        if (!$reference) {
            return redirect()->to(env('FRONTEND_URL') . '/dashboard?payment=failed');
        }

        $verification = $this->paystackService->verifyTransaction($reference);

        if ($verification && ($verification['data']['status'] ?? '') === 'success') {
            $metadata = $verification['data']['metadata'] ?? [];
            $escrowId = $metadata['escrow_id'] ?? null;

            DB::transaction(function () use ($verification, $escrowId) {
                Payment::updateOrCreate(
                    ['reference' => $verification['data']['reference']],
                    [
                        'escrow_id'      => $escrowId,
                        'amount'         => $verification['data']['amount'] / 100,
                        'status'         => 'completed',
                        'payment_method' => 'paystack_card',
                        'paid_at'        => now(),
                    ]
                );

                if ($escrowId) {
                    $this->updateEscrowProgress($escrowId);
                }
            });

            return redirect()->to(env('FRONTEND_URL') . "/agent/escrows?payment=success&reference={$reference}");
        }

        return redirect()->to(env('FRONTEND_URL') . '/agent/escrows?payment=failed');
    }

    /**
     * Paystack webhook for real-time payment events
     * POST /api/v1/paystack/webhook
     */
    public function paystackWebhook(Request $request)
    {
        if (!$this->verifyPaystackSignature($request)) {
            Log::error('Paystack webhook signature invalid');
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $event = $request->json('event');

        if ($event === 'charge.success') {
            $data     = $request->json('data');
            $metadata = $data['metadata'] ?? [];
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

        return response()->json(['message' => 'Webhook processed.']);
    }

    // ─── HELPERS ──────────────────────────────────────────────────────────────

    private function updateEscrowProgress($escrowId)
    {
        $totalPaid = Payment::where('escrow_id', $escrowId)
            ->where('status', 'completed')
            ->sum('amount');

        $escrow = Escrow::find($escrowId);

        if ($escrow) {
            $escrow->update([
                'total_paid'      => $totalPaid,
                'remaining'       => max(0, $escrow->amount - $totalPaid),
                'is_fully_funded' => $totalPaid >= $escrow->amount,
                'status'          => $totalPaid >= $escrow->amount ? 'funded' : 'partially_funded',
            ]);
        }
    }

    private function verifyPaystackSignature(Request $request): bool
    {
        $signature = $request->header('x-paystack-signature');
        $secret    = config('paystack.secret_key');

        if (!$secret || !$signature) {
            return false;
        }

        $computed = hash_hmac('sha512', $request->getContent(), $secret);
        return hash_equals($signature, $computed);
    }
}