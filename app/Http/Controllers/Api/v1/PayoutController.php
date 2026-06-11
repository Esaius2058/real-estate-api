<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\EscrowMilestone;
use App\Services\PaystackService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class PayoutController extends Controller
{
    protected PaystackService $paystack;

    /**
     * Inject Paystack Service as the singular financial distribution driver.
     */
    public function __construct(PaystackService $paystack)
    {
        $this->paystack = $paystack;
    }

    public function releaseMilestonePayout($milestoneId)
    {
        try {
            return DB::transaction(function () use ($milestoneId) {
                // Lock the milestone for update to prevent double disbursal attempts
                $milestone = EscrowMilestone::with('escrow.seller')->lockForUpdate()->findOrFail($milestoneId);

                if ($milestone->status !== 'approved') {
                    return response()->json(['message' => 'Milestone must be confirmed and approved before payout execution'], 422);
                }

                if ($milestone->escrow->status === 'disputed') {
                    return response()->json(['message' => 'Escrow workspace is currently locked under systemic arbitration'], 422);
                }

                // Verify structural Paystack recipient channel profile mapping for the target seller
                $recipientCode = DB::table('seller_payment_profiles')
                    ->where('user_id', $milestone->escrow->seller_id)
                    ->value('paystack_recipient_code');

                if (!$recipientCode) {
                    return response()->json(['message' => 'Seller routing parameters not initialized on Paystack infrastructure rails'], 422);
                }

                // Calculate platform transaction parameters (5% system fee retention ruleset)
                $feePercentage = 0.05; 
                $platformFee = $milestone->amount * $feePercentage;
                $netVendorPayout = $milestone->amount - $platformFee;

                // FIX: Defined the tracker reference accurately in the DB table ledger context
                $payoutId = DB::table('settlements')->insertGetId([
                    'escrow_id' => $milestone->escrow_id,
                    'user_id' => $milestone->escrow->seller_id,
                    'amount' => $netVendorPayout,
                    'status' => 'processing',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Fire Paystack Outbound Balance Disbursal Payout (Handles M-Pesa & Banks automatically)
                $paystackResponse = $this->paystack->initiateTransfer(
                    $netVendorPayout, 
                    $recipientCode, 
                    "Milestone Clear Ref ID: #{$milestone->id}"
                );

                // FIX: Eliminated dynamic object assumptions; updating via explicit DB fluent queries
                if (!$paystackResponse) {
                    DB::table('settlements')->where('id', $payoutId)->update([
                        'status' => 'failed',
                        'updated_at' => now()
                    ]);
                    return response()->json(['message' => 'Paystack balance engine transfer processing failure'], 500);
                }

                $milestone->update(['status' => 'released', 'released_at' => now()]);
                
                DB::table('settlements')->where('id', $payoutId)->update([
                    'status' => 'completed',
                    'transaction_reference' => $paystackResponse['data']['transfer_code'] ?? null,
                    'updated_at' => now()
                ]);

                // Append reference metrics to master audit logs
                DB::table('transaction_logs')->insert([
                    'escrow_id' => $milestone->escrow_id,
                    'action' => 'MILESTONE_PAYSTACK_DISBURSAL_SUCCESS',
                    'amount' => (int)($netVendorPayout * 100),
                    'payload_snapshot' => json_encode($paystackResponse),
                    'created_at' => now()
                ]);

                // Update operational parent balance state criteria down the stream if applicable
                if (method_exists($milestone->escrow, 'updateProgress')) {
                    $milestone->escrow->updateProgress();
                }

                // Retrieve the updated settlement array to return cleanly
                $finalPayoutRecord = DB::table('settlements')->find($payoutId);

                return response()->json(['success' => true, 'payout' => $finalPayoutRecord]);
            });
        } catch (Exception $e) {
            Log::error('Paystack Payout execution loop exception', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Financial gateway runtime payout rejection: ' . $e->getMessage()], 500);
        }
    }

    public function handlePaystackTransferWebhook(Request $request)
    {
        // Log incoming webhook data from Paystack system transitions
        Log::info('Paystack Payout Outbound Response Webhook Received', $request->all());
        return response()->json(['status' => 'success'], 200);
    }
}