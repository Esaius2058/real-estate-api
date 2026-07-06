<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Escrow;
use App\Models\EscrowDispute;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

use App\Services\PaystackService;

class AdminDashboardController extends Controller
{
    protected PaystackService $paystack;

    /**
     * Map controller runtime onto the primary system payment infrastructure driver.
     */
    public function __construct(PaystackService $paystack)
    {
        $this->paystack = $paystack;
    }

    public function metrics()
    {
        try {
            // total_revenue
            $totalRevenue = (float) Payment::where('status', 'completed')->sum('amount');

            // subscription_revenue
            $subscriptionRevenue = (float) Payment::where('status', 'completed')
                ->where('payment_type', 'subscription')
                ->sum('amount');

            // escrow_revenue
            $escrowRevenue = (float) Payment::where('status', 'completed')
                ->where('payment_type', 'escrow')
                ->sum('amount');

            // mrr calculation
            $activeSubs = \App\Models\Subscription::with('tier')
                ->where('status', 'active')
                ->where('ends_at', '>', now())
                ->get();
            $mrr = 0.0;
            foreach ($activeSubs as $sub) {
                if (!$sub->tier) continue;
                if ($sub->billing_cycle === 'yearly') {
                    $mrr += $sub->tier->yearly_price / 12;
                } else {
                    $mrr += $sub->tier->monthly_price;
                }
            }

            // active_subscriber_count
            $activeSubscriberCount = \App\Models\Subscription::where('status', 'active')
                ->where('ends_at', '>', now())
                ->count();

            // total_escrow_held (status = funded, inspection, closing)
            $totalEscrowHeld = (float) Escrow::whereIn('status', ['funded', 'inspection', 'closing'])->sum('amount');

            // total_escrow_released
            $totalEscrowReleased = (float) Escrow::where('status', 'completed')->sum('amount');

            // active_escrow_contracts
            $activeEscrowContracts = Escrow::whereIn('status', ['funded', 'inspection', 'closing'])->count();

            // pending_disputes_count
            $pendingDisputesCount = EscrowDispute::where('status', 'pending')->count();

            // revenue_by_tier (grouped)
            $revenueByTier = Payment::where('payment_type', 'subscription')
                ->where('payments.status', 'completed')
                ->join('subscriptions', 'payments.subscription_id', '=', 'subscriptions.id')
                ->join('subscription_tiers', 'subscriptions.tier_id', '=', 'subscription_tiers.id')
                ->select('subscription_tiers.name', DB::raw('SUM(payments.amount) as total_revenue'))
                ->groupBy('subscription_tiers.name')
                ->get()
                ->pluck('total_revenue', 'name')
                ->toArray();

            // gateway_stats (success/fail/pending counts + rate per payment_method)
            $gatewayRaw = Payment::select('payment_method', 'status', DB::raw('count(*) as count'))
                ->groupBy('payment_method', 'status')
                ->get();

            $gatewayStats = [];
            foreach ($gatewayRaw as $row) {
                $method = $row->payment_method ?? 'unknown';
                $status = $row->status;
                $count = (int)$row->count;

                if (!isset($gatewayStats[$method])) {
                    $gatewayStats[$method] = [
                        'completed' => 0,
                        'failed' => 0,
                        'pending' => 0,
                        'total' => 0,
                        'success_rate' => 0.0,
                    ];
                }

                if (in_array($status, ['completed', 'failed', 'pending'])) {
                    $gatewayStats[$method][$status] = $count;
                }
                $gatewayStats[$method]['total'] += $count;
            }

            foreach ($gatewayStats as $method => &$stats) {
                if ($stats['total'] > 0) {
                    $stats['success_rate'] = round(($stats['completed'] / $stats['total']) * 100, 2);
                }
            }

            // Fetch pending disputes for the admin dashboard frontend component compatibility
            $disputes = EscrowDispute::with(['escrow.buyer', 'escrow.seller', 'raisedBy'])
                ->where('status', 'pending')
                ->get();

            return response()->json([
                'total_revenue' => $totalRevenue,
                'subscription_revenue' => $subscriptionRevenue,
                'escrow_revenue' => $escrowRevenue,
                'mrr' => $mrr,
                'active_subscriber_count' => $activeSubscriberCount,
                'total_escrow_held' => $totalEscrowHeld,
                'total_escrow_released' => $totalEscrowReleased,
                'active_escrow_contracts' => $activeEscrowContracts,
                'pending_disputes_count' => $pendingDisputesCount,
                'revenue_by_tier' => $revenueByTier,
                'gateway_stats' => $gatewayStats,

                // Frontend compatibility mapping keys
                'total_locked_volume' => $totalEscrowHeld,
                'disputes_count' => $pendingDisputesCount,
                'disputes' => $disputes,
            ]);
        } catch (Exception $e) {
            Log::error('Admin analytics calculation engine failure', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Analytics processing failure: ' . $e->getMessage()], 500);
        }
    }

    public function disputes()
    {
        return response()->json(EscrowDispute::with(['escrow.buyer', 'escrow.seller', 'raisedBy'])->latest()->paginate(15));
    }

    public function resolveDispute(Request $request, $id)
    {
        $data = $request->validate([
            'resolution' => 'required|in:force_refund,force_payout,manual',
            'admin_notes' => 'required|string|min:10',
        ]);

        try {
            return DB::transaction(function () use ($data, $id) {
                $dispute = EscrowDispute::with('escrow.buyer', 'escrow.seller')->lockForUpdate()->findOrFail($id);
                $escrow = $dispute->escrow;

                if ($dispute->status === 'resolved') {
                    return response()->json(['message' => 'This arbitration profile has already closed'], 422);
                }

                // Explicit total extraction conversion into absolute minor units (cents/pesewas)
                $minorUnitAmount = (int)($escrow->total_paid * 100);

                if ($data['resolution'] === 'force_refund') {
                    $escrow->update(['status' => 'cancelled']);
                    
                    // Route structural distribution back to the buying entity profile
                    $recipientCode = DB::table('buyer_payment_profiles')->where('user_id', $escrow->buyer_id)->value('paystack_recipient_code');
                    if (!$recipientCode) {
                        throw new Exception("Buyer payment payout channel allocation markers missing on Paystack rails.");
                    }

                    $payoutData = $this->paystack->initiateTransfer($minorUnitAmount, $recipientCode, "Arbitration Force Refund Block #{$escrow->id}");
                    $this->logOverrideAction($escrow->id, 'ARBITRATION_PAYSTACK_FORCE_REFUND', $minorUnitAmount, $payoutData);

                } elseif ($data['resolution'] === 'force_payout') {
                    $escrow->update(['status' => 'completed', 'completed_at' => now()]);
                    
                    // Route structural distribution straight down to the selling entity profile
                    $recipientCode = DB::table('seller_payment_profiles')->where('user_id', $escrow->seller_id)->value('paystack_recipient_code');
                    if (!$recipientCode) {
                        throw new Exception("Seller payment payout channel allocation markers missing on Paystack rails.");
                    }

                    $payoutData = $this->paystack->initiateTransfer($minorUnitAmount, $recipientCode, "Arbitration Force Payout Block #{$escrow->id}");
                    $this->logOverrideAction($escrow->id, 'ARBITRATION_PAYSTACK_FORCE_PAYOUT', $minorUnitAmount, $payoutData);
                }

                // Fixed error: Changed dynamic helper auth()->id() to the static Facade to pass Intelephense inspections
                $dispute->update([
                    'status' => 'resolved',
                    'resolution' => $data['resolution'],
                    'admin_notes' => $data['admin_notes'],
                    'resolved_by_admin_id' => Auth::id(),
                ]);

                return response()->json(['success' => true, 'dispute' => $dispute]);
            });
        } catch (Exception $e) {
            Log::error('Dispute arbitration override failure', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'System-wide resolution processing failure: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Commit financial movement metrics directly into core transaction trace ledgers.
     */
    protected function logOverrideAction(int $escrowId, string $action, int $amount, array $snapshot): void
    {
        DB::table('transaction_logs')->insert([
            'escrow_id' => $escrowId,
            'action' => $action,
            'amount' => $amount,
            'payload_snapshot' => json_encode($snapshot),
            'created_at' => now()
        ]);
    }
}