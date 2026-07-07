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
        $accountReference = $request->escrow_id
            ? 'ESCROW-' . $request->escrow_id
            : 'MAKAO-' . strtoupper(\Illuminate\Support\Str::random(10));

        $result = $this->darajaService->stkPush(
            $request->phone,
            (int) round($request->amount),
            $accountReference
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
                    'paid_at'        => now(),
                ]);

                if ($payment->escrow_id) {
                    $this->updateEscrowProgress($payment->escrow_id);
                }

                if ($payment->subscription_id) {
                    \App\Models\Subscription::activateFromPaymentId($payment->subscription_id);
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

        // If the callback hasn't landed yet (common in local dev without a
        // public tunnel for MPESA_CALLBACK_URL), actively ask Safaricom
        // instead of leaving the frontend polling a status that will never
        // change on its own.
        if ($payment->status === 'pending') {
            try {
                $result = $this->darajaService->queryStkStatus($checkoutRequestID);
                $resultCode = $result['ResultCode'] ?? null;

                if ($resultCode !== null && (string) $resultCode === '0') {
                    $payment->update(['status' => 'completed', 'paid_at' => now()]);

                    if ($payment->escrow_id) {
                        $this->updateEscrowProgress($payment->escrow_id);
                    }

                    if ($payment->subscription_id) {
                        \App\Models\Subscription::activateFromPaymentId($payment->subscription_id);
                    }
                } elseif ($resultCode !== null && (string) $resultCode !== '0' && (string) $resultCode !== '1032') {
                    // 1032 = "request cancelled by user" while still awaiting
                    // PIN entry on some Daraja sandbox responses; anything
                    // else non-zero is a genuine failure.
                    $payment->update(['status' => 'failed']);
                }
                // If Safaricom has no result yet, leave status as 'pending'
                // — the user probably hasn't entered their PIN yet.
            } catch (\Exception $e) {
                Log::warning('STK status query failed, leaving payment pending', ['error' => $e->getMessage()]);
            }
        }

        return response()->json([
            'success'        => true,
            'status'         => $payment->fresh()->status,
            'receipt_number' => $payment->fresh()->receipt_number,
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
                    'escrow_id'             => $escrow->id,
                    'user_id'               => $user->id,
                    'agency_id'             => $escrow->agency_id ?? 1,
                    'amount'                => $request->amount,
                    'transaction_reference' => $result['data']['reference'] ?? null,
                    'status'                => 'pending',
                    'payment_type'          => 'escrow',
                    'payment_method'        => 'paystack_card',
                    'currency'              => 'KES',
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
        $frontendUrl = rtrim(env('FRONTEND_URL', 'http://localhost:5173'), '/');
        $reference   = $request->query('reference') ?? $request->query('trxref');

        if (!$reference) {
            return redirect()->to($frontendUrl . '/agent/escrows?payment=failed');
        }

        $verification = $this->paystackService->verifyTransaction($reference);

        if ($verification && ($verification['data']['status'] ?? '') === 'success') {
            $metadata = $verification['data']['metadata'] ?? [];
            $escrowId = $metadata['escrow_id'] ?? null;
            $isSubscription = ($metadata['purpose'] ?? null) === 'subscription';

            if ($isSubscription) {
                // Subscription activation/payment recording is handled by
                // SubscriptionController::verify(), which the frontend calls
                // directly on the /agent/plans page. Just route the browser
                // there; don't duplicate escrow-shaped Payment rows for it.
                return redirect()->to($frontendUrl . "/agent/plans?payment=success&reference={$reference}");
            }

            DB::transaction(function () use ($verification, $escrowId, $reference) {
                Payment::updateOrCreate(
                    ['transaction_reference' => $reference],
                    [
                        'escrow_id'      => $escrowId,
                        'agency_id'      => $escrowId ? (Escrow::find($escrowId)->agency_id ?? 1) : 1,
                        'amount'         => $verification['data']['amount'] / 100,
                        'status'         => 'completed',
                        'payment_type'   => 'escrow',
                        'payment_method' => 'paystack_card',
                        'currency'       => 'KES',
                        'paid_at'        => now(),
                    ]
                );

                if ($escrowId) {
                    $this->updateEscrowProgress($escrowId);
                }
            });

            return redirect()->to($frontendUrl . "/agent/escrows?payment=success&reference={$reference}");
        }

        return redirect()->to($frontendUrl . '/agent/escrows?payment=failed');
    }

    /**
     * Paystack webhook for real-time payment events
     * POST /api/v1/paystack/webhook
     */
    /**
     * NOTE: not currently routed. Only one Paystack webhook URL is
     * registered app-wide (routes/api_v1.php -> SubscriptionController::webhook),
     * which now dispatches to both subscription and escrow charge events.
     * This method is kept for reference / in case you split the webhook
     * URL per-purpose later, but it will not receive live traffic as-is.
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
                    ['transaction_reference' => $data['reference']],
                    [
                        'escrow_id'      => $escrowId,
                        'agency_id'      => $escrowId ? (Escrow::find($escrowId)->agency_id ?? 1) : 1,
                        'amount'         => $data['amount'] / 100,
                        'status'         => 'completed',
                        'payment_type'   => 'escrow',
                        'payment_method' => 'paystack_card',
                        'currency'       => 'KES',
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
        $escrow = Escrow::lockForUpdate()->find($escrowId);

        if ($escrow) {
            $escrow->applyPayment();
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