<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Escrow;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;
use App\Services\PaystackService;
use App\Models\User;
use App\Scopes\AgencyScope;
use App\Models\Property;
use App\Models\EscrowDispute;
use App\Models\ActivityLog;

class AdminDashboardController extends Controller
{
    protected $paystack;

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
            $totalVolume = Escrow::withoutGlobalScope(AgencyScope::class)
                ->whereIn('status', ['funded', 'inspection', 'closing', 'completed'])
                ->sum('amount');
                
            $activeEscrows = Escrow::withoutGlobalScope(AgencyScope::class)
                ->whereIn('status', ['funded', 'inspection', 'closing'])
                ->count();
                
            $pendingDisputes = EscrowDispute::withoutGlobalScope(AgencyScope::class)
                ->where('status', 'pending')
                ->count();
            
            $saasRevenue = Payment::where('payment_method', 'paystack_card')
                ->where('status', 'completed')
                ->whereHas('user', function($q) {
                    $q->withoutGlobalScope(AgencyScope::class)->whereExists(function($sub) {
                        $sub->select(DB::raw(1))
                            ->from('subscriptions')
                            ->whereRaw('subscriptions.subscribable_id = users.id');
                    });
                })->sum('amount');

            return response()->json([
                'metrics' => [
                    'total_escrow_volume' => (float)$totalVolume,
                    'active_escrow_contracts' => $activeEscrows,
                    'pending_disputes_count' => $pendingDisputes,
                    'saas_recurring_revenue' => (float)$saasRevenue,
                ],
                'recent_transactions' => Payment::with('user')->latest()->take(5)->get()
            ]);
        } catch (Exception $e) {
            Log::error('Admin analytics calculation engine failure', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Analytics processing failure'], 500);
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
                'created_at as addDate', 
                'last_active_at as lastActive'
            ])
            ->get();

        return response()->json($users);
    }

    public function resolveDispute(Request $request, $id)
    {
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

                // Explicit total extraction conversion into absolute minor units (cents/pesewas)
                $minorUnitAmount = (int)($escrow->total_paid * 100);

                if (in_array($data['resolution'], ['force_refund', 'refund_to_buyer'])) {
                    $escrow->update(['status' => 'cancelled']);
                    
                    // Route structural distribution back to the buying entity profile
                    $recipientCode = DB::table('buyer_payment_profiles')->where('user_id', $escrow->buyer_id)->value('paystack_recipient_code');
                    if (!$recipientCode) {
                        throw new Exception("Buyer payment payout channel allocation markers missing on Paystack rails.");
                    }

                    $payoutData = $this->paystack->initiateTransfer($minorUnitAmount, $recipientCode, "Arbitration Force Refund Block #{$escrow->id}");
                    $this->logOverrideAction($escrow->id, 'ARBITRATION_PAYSTACK_FORCE_REFUND', $minorUnitAmount, $payoutData);

                } elseif (in_array($data['resolution'], ['force_payout', 'released_to_seller'])) {
                    $escrow->update(['status' => 'completed', 'completed_at' => now()]);
                    
                    // Route structural distribution straight down to the selling entity profile
                    $recipientCode = DB::table('seller_payment_profiles')->where('user_id', $escrow->seller_id)->value('paystack_recipient_code');
                    if (!$recipientCode) {
                        throw new Exception("Seller payment payout channel allocation markers missing on Paystack rails.");
                    }

                    $payoutData = $this->paystack->initiateTransfer($minorUnitAmount, $recipientCode, "Arbitration Force Payout Block #{$escrow->id}");
                    $this->logOverrideAction($escrow->id, 'ARBITRATION_PAYSTACK_FORCE_PAYOUT', $minorUnitAmount, $payoutData);
                }

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

    /**
     * UNIFIED DASHBOARD HUB
     * Returns all data needed for the admin dashboard in ONE API call
     */
   /**
     * UNIFIED DASHBOARD HUB
     * Returns all data needed for the admin dashboard safely, bypassing global multi-tenant scopes.
     */
    public function dashboardHub() 
    {
        // Initialize structural fallbacks to guarantee front-end data keys are always populated
        $totalVolume = 0; 
        $totalUsers = 0; 
        $totalAgencies = 0; 
        $totalProperties = 0; 
        $pendingKycCount = 0;
        
        $disputes = collect(); 
        $recentProperties = collect(); 
        $recentAgencies = collect(); 
        $recentLogs = collect();

        // 1. Process Total Escrow Volume Accrual Metrics
        try { 
            $totalVolume = Escrow::withoutGlobalScope(AgencyScope::class)
                ->whereIn('status', ['funded', 'inspection', 'closing'])
                ->sum('amount'); 
        } catch (Exception $e) { 
            Log::warning('Dashboard Hub - Escrow volume accumulation failed: ' . $e->getMessage()); 
        }

        // 2. Process Core Aggregates Counters
        try { $totalUsers = User::withoutGlobalScope(AgencyScope::class)->count(); } catch (Exception $e) {}
        try { $totalAgencies = DB::table('agencies')->count(); } catch (Exception $e) {}
        try { $totalProperties = Property::withoutGlobalScope(AgencyScope::class)->count(); } catch (Exception $e) {}
        
        // 3. Gather Active Dispute Arbitration Records
        try {
            $disputes = EscrowDispute::withoutGlobalScope(AgencyScope::class)
                ->with(['escrow.buyer', 'escrow.seller', 'raisedBy'])
                ->where('status', 'pending')
                ->get();
        } catch (Exception $e) {
            Log::warning('Dashboard Hub - Dispute retrieval failure: ' . $e->getMessage());
        }

        // 4. Gather Real-Estate Listing Pipelines
        try {
            $recentProperties = Property::withoutGlobalScope(AgencyScope::class)
                ->with('agent')
                ->latest()
                ->take(3)
                ->get();
        } catch (Exception $e) {}

        // 5. Gather Corporate Workspace Context profiles
        try {
            $recentAgencies = DB::table('agencies')->latest()->take(5)->get();
        } catch (Exception $e) {
            try { 
                $recentAgencies = \App\Models\Agency::latest()->take(5)->get(); 
            } catch (Exception $ex) {}
        }

        // 6. Gather Pending KYC Document Verifications
        try {
            if (class_exists('\App\Models\VaultDocument')) {
                $pendingKycCount = \App\Models\VaultDocument::withoutGlobalScope(AgencyScope::class)
                    ->where('status', 'pending')
                    ->count();
            }
        } catch (Exception $e) {}

        // 7. Process System-Wide Activity Audit Trails (Using an explicit safe fallback pattern)
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
            // Fallback block if the Eloquent model structure maps to an unorthodox schema variant
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

        // Return clean, well-formed response payload mapping straight to React expectations
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
     * GLOBAL LEADS PIPELINE OVERVIEW
     * Returns all leads from all agents across all tenant groups safely.
     */
    public function globalLeads()
    {
        try {
            // 1. Strip all global multi-tenant scopes without relying on explicit class paths
            $query = \App\Models\Lead::withoutGlobalScopes();
            
            // 2. Attempt to eager-load relationships safely
            try {
                // If your relationships are named differently, this catch block intercepts the error
                $leads = $query->with(['agent', 'user'])->latest()->get();
            } catch (\Exception $relException) {
                \Illuminate\Support\Facades\Log::warning('Admin Global Leads - Relationship eager loading failed: ' . $relException->getMessage());
                // Fallback: Fetch plain leads records without relationship context to avoid crashing the screen
                $leads = $query->latest()->get();
            }

            return response()->json([
                'success' => true,
                'data' => $leads
            ]);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Admin Global Leads - Eloquent pipeline failed: ' . $e->getMessage());
            
            // 3. Ultimate structural fallback: Raw Database Query bypasses model issues entirely
            try {
                $leads = \Illuminate\Support\Facades\DB::table('leads')
                    ->latest()
                    ->get();
                    
                return response()->json([
                    'success' => true,
                    'data' => $leads
                ]);
            } catch (\Exception $dbException) {
                \Illuminate\Support\Facades\Log::emergency('Admin Global Leads - Complete system infrastructure failure: ' . $dbException->getMessage());
                
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to compile system-wide leads dataset.',
                    'error_details' => $dbException->getMessage()
                ], 500);
            }
        }
    }
}