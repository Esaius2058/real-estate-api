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
use App\Models\User;
use App\Scopes\AgencyScope;

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
            $totalVolume = Escrow::whereIn('status', ['funded', 'inspection', 'closing', 'completed'])->sum('amount');
            $activeEscrows = Escrow::whereIn('status', ['funded', 'inspection', 'closing'])->count();
            $pendingDisputes = EscrowDispute::where('status', 'pending')->count();
            
            $saasRevenue = Payment::where('payment_method', 'paystack_card')
                ->where('status', 'completed')
                ->whereHas('user', function($q) {
                    $q->whereExists(function($sub) {
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
        return response()->json(EscrowDispute::with(['escrow.buyer', 'escrow.seller', 'raisedBy'])->latest()->paginate(15));
    }
public function getUsers()
{
    // Use the class name to disable the specific scope
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