<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Escrow;
use App\Models\EscrowDispute;
use App\Models\Payment;
use App\Models\User;
use App\Models\Property;
use App\Models\ActivityLog;
use App\Models\Subscription;
use App\Models\Agency;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;
use App\Services\PaystackService;
use App\Scopes\AgencyScope;

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
            // Revenue calculations
            $totalRevenue = (float) Payment::where('status', 'completed')->sum('amount');

            $subscriptionRevenue = (float) Payment::where('status', 'completed')
                ->where('payment_type', 'subscription')
                ->sum('amount');

            $escrowRevenue = (float) Payment::where('status', 'completed')
                ->where('payment_type', 'escrow')
                ->sum('amount');

            // MRR calculation
            $activeSubs = Subscription::with('tier')
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

            // Active subscriber count
            $activeSubscriberCount = Subscription::where('status', 'active')
                ->where('ends_at', '>', now())
                ->count();

            // Escrow stats (Applying Global Scope Bypass for Admin visibility)
            $totalEscrowHeld = (float) Escrow::withoutGlobalScope(AgencyScope::class)
                ->whereIn('status', ['funded', 'inspection', 'closing'])
                ->sum('amount');

            $totalEscrowReleased = (float) Escrow::withoutGlobalScope(AgencyScope::class)
                ->where('status', 'completed')
                ->sum('amount');

            $activeEscrowContracts = Escrow::withoutGlobalScope(AgencyScope::class)
                ->whereIn('status', ['funded', 'inspection', 'closing'])
                ->count();

            $pendingDisputesCount = EscrowDispute::withoutGlobalScope(AgencyScope::class)
                ->where('status', 'pending')
                ->count();

            // Revenue by tier
            $revenueByTier = Payment::where('payment_type', 'subscription')
                ->where('payments.status', 'completed')
                ->join('subscriptions', 'payments.subscription_id', '=', 'subscriptions.id')
                ->join('subscription_tiers', 'subscriptions.tier_id', '=', 'subscription_tiers.id')
                ->select('subscription_tiers.name', DB::raw('SUM(payments.amount) as total_revenue'))
                ->groupBy('subscription_tiers.name')
                ->get()
                ->pluck('total_revenue', 'name')
                ->toArray();

            // Gateway stats
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

            // SaaS Revenue Fallback (Original Logic)
            $saasRevenue = Payment::where('payment_method', 'paystack_card')
                ->where('status', 'completed')
                ->whereHas('user', function($q) {
                    $q->withoutGlobalScope(AgencyScope::class)->whereExists(function($sub) {
                        $sub->select(DB::raw(1))
                            ->from('subscriptions')
                            ->whereRaw('subscriptions.subscribable_id = users.id');
                    });
                })->sum('amount');

            // Fetch pending disputes
            $disputes = EscrowDispute::withoutGlobalScope(AgencyScope::class)
                ->with(['escrow.buyer', 'escrow.seller', 'raisedBy'])
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
                
                // Original frontend expected mapping keys
                'metrics' => [
                    'total_escrow_volume' => $totalEscrowHeld,
                    'active_escrow_contracts' => $activeEscrowContracts,
                    'pending_disputes_count' => $pendingDisputesCount,
                    'saas_recurring_revenue' => (float)$saasRevenue,
                ],
                'recent_transactions' => Payment::with('user')->latest()->take(5)->get(),
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
        return response()->json(
            EscrowDispute::withoutGlobalScope(AgencyScope::class)
                ->with(['escrow.buyer', 'escrow.seller', 'raisedBy'])
                ->latest()
                ->paginate(15)
        );
    }

    public function getUsers()
    {
        $users = User::withoutGlobalScope(AgencyScope::class) 
            ->select([
                'id', 'name', 'email', 'role', 'status',
                'last_active_at as lastActive'
            ])
            ->get();

        return response()->json($users);
    }

    public function resolveDispute(Request $request, $id)
    {
        // Merged expanded resolutions logic
        $data = $request->validate([
            'resolution' => 'required|in:force_refund,force_payout,manual,refund_to_buyer,released_to_seller',
            'admin_notes' => 'required|string|min:10',
        ]);

        try {
            return DB::transaction(function () use ($data, $id) {
                $dispute = EscrowDispute::withoutGlobalScope(AgencyScope::class)
                    ->with('escrow.buyer', 'escrow.seller')
                    ->lockForUpdate()
                    ->findOrFail($id);
                    
                $escrow = $dispute->escrow;

                if ($dispute->status === 'resolved') {
                    return response()->json(['message' => 'This arbitration profile has already closed'], 422);
                }

                $minorUnitAmount = (int)($escrow->total_paid * 100);

                if (in_array($data['resolution'], ['force_refund', 'refund_to_buyer'])) {
                    $escrow->update(['status' => 'cancelled']);
                    
                    $recipientCode = DB::table('buyer_payment_profiles')->where('user_id', $escrow->buyer_id)->value('paystack_recipient_code');
                    if (!$recipientCode) {
                        throw new Exception("Buyer payment payout channel allocation markers missing on Paystack rails.");
                    }

                    $payoutData = $this->paystack->initiateTransfer($minorUnitAmount, $recipientCode, "Arbitration Force Refund Block #{$escrow->id}");
                    $this->logOverrideAction($escrow->id, 'ARBITRATION_PAYSTACK_FORCE_REFUND', $minorUnitAmount, $payoutData);

                } elseif (in_array($data['resolution'], ['force_payout', 'released_to_seller'])) {
                    $escrow->update(['status' => 'completed', 'completed_at' => now()]);
                    
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
     * UNIFIED DASHBOARD HUB
     * Returns all data needed for the admin dashboard safely, bypassing global multi-tenant scopes.
     */
    public function dashboardHub() 
    {
        $totalVolume = 0; 
        $totalUsers = 0; 
        $totalAgencies = 0; 
        $totalProperties = 0; 
        $pendingKycCount = 0;
        
        $disputes = collect(); 
        $recentProperties = collect(); 
        $recentAgencies = collect(); 
        $recentLogs = collect();

        try { 
            $totalVolume = Escrow::withoutGlobalScope(AgencyScope::class)
                ->whereIn('status', ['funded', 'inspection', 'closing'])
                ->sum('amount'); 
        } catch (Exception $e) { 
            Log::warning('Dashboard Hub - Escrow volume accumulation failed: ' . $e->getMessage()); 
        }

        try { $totalUsers = User::withoutGlobalScope(AgencyScope::class)->count(); } catch (Exception $e) {}
        try { $totalAgencies = DB::table('agencies')->count(); } catch (Exception $e) {}
        try { $totalProperties = Property::withoutGlobalScope(AgencyScope::class)->count(); } catch (Exception $e) {}
        
        try {
            $disputes = EscrowDispute::withoutGlobalScope(AgencyScope::class)
                ->with(['escrow.buyer', 'escrow.seller', 'raisedBy'])
                ->where('status', 'pending')
                ->get();
        } catch (Exception $e) {
            Log::warning('Dashboard Hub - Dispute retrieval failure: ' . $e->getMessage());
        }

        try {
            $recentProperties = Property::withoutGlobalScope(AgencyScope::class)
                ->with('agent')
                ->latest()
                ->take(3)
                ->get();
        } catch (Exception $e) {}

        try {
            $recentAgencies = DB::table('agencies')->latest()->take(5)->get();
        } catch (Exception $e) {
            try { 
                $recentAgencies = \App\Models\Agency::latest()->take(5)->get(); 
            } catch (Exception $ex) {}
        }

        try {
            if (class_exists('\App\Models\VaultDocument')) {
                $pendingKycCount = \App\Models\VaultDocument::withoutGlobalScope(AgencyScope::class)
                    ->where('status', 'pending')
                    ->count();
            }
        } catch (Exception $e) {}

        try {
            $recentLogs = ActivityLog::withoutGlobalScope(AgencyScope::class)
                ->with('user')
                ->latest()
                ->take(20)
                ->get()
                ->map(function($log) {
                    return [
                        'id' => $log->id,
                        'action' => $log->action,
                        'description' => $log->description,
                        'created_at' => $log->created_at,
                        'user_name' => $log->user->name ?? $log->user_name ?? 'System Auto'
                    ];
                });
        } catch (Exception $e) {
            try {
                $recentLogs = DB::table('activity_logs')
                    ->leftJoin('users', 'activity_logs.user_id', '=', 'users.id')
                    ->select(
                        'activity_logs.id', 
                        'activity_logs.action', 
                        'activity_logs.description', 
                        'activity_logs.created_at', 
                        'users.name as user_name'
                    )
                    ->orderBy('activity_logs.created_at', 'desc')
                    ->limit(20)
                    ->get();
            } catch (Exception $ex) {
                Log::error('Dashboard Hub - Audit trail delivery system collapsed: ' . $ex->getMessage());
            }
        }

        return response()->json([
            'total_locked_volume' => (float)$totalVolume,
            'disputes' => $disputes,
            'disputes_count' => $disputes->count(),
            'platform_revenue' => (float)($totalVolume * 0.015),
            'total_users' => $totalUsers,
            'total_agencies' => $totalAgencies,
            'total_properties' => $totalProperties,
            'recent_properties' => $recentProperties,
            'recent_agencies' => $recentAgencies,
            'pending_kyc_count' => $pendingKycCount,
            'recent_logs' => $recentLogs,
        ]);
    }

    public function getDashboardData(Request $request)
    {
        return response()->json([
            'users_count' => User::withoutGlobalScope(AgencyScope::class)->count(),
            'properties_count' => Property::withoutGlobalScope(AgencyScope::class)->count(),
            'escrows_count' => Escrow::withoutGlobalScope(AgencyScope::class)->count(),
            'agencies_count' => \App\Models\Agency::count(),
            'disputes_count' => EscrowDispute::withoutGlobalScope(AgencyScope::class)->where('status', 'pending')->count(),
            'disputes' => EscrowDispute::withoutGlobalScope(AgencyScope::class)->where('status', 'pending')->with('raisedBy')->get(),
            'recent_logs' => ActivityLog::withoutGlobalScope(AgencyScope::class)->latest()->take(15)->get(),
        ]);
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