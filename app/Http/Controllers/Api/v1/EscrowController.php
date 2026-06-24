<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Escrow;
use App\Models\EscrowMilestone;
use App\Models\EscrowDispute;
use App\Models\Property;
use App\Services\PaystackService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class EscrowController extends Controller
{
    protected PaystackService $paystack;
    protected $activity;
    // Inject your restored Paystack driver into the core controller architecture
    public function __construct(PaystackService $paystack, ActivityService $activity)
    {
        $this->paystack = $paystack;
        $this->activity = $activity;
    }

    public function index(Request $request)
    {
        $user = Auth::user();
        $query = Escrow::with(['property', 'buyer', 'seller']);

        // Filter based on user workspace context
        if (($user->role ?? '') !== 'admin') {
            $query->where(function ($q) use ($user) {
                $q->where('buyer_id', $user->id)
                  ->orWhere('seller_id', $user->id);
            });
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        return response()->json($query->latest()->paginate(15));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'property_id' => 'required|exists:properties,id',
            'amount' => 'required|numeric|min:1', // Sent as whole currency unit (e.g. KES)
            'terms' => 'nullable|string',
        ]);

        try {
            return DB::transaction(function () use ($data) {
                $property = Property::findOrFail($data['property_id']);
                
                $escrow = Escrow::create([
                    'property_id' => $property->id,
                    'buyer_id' => Auth::id(),
                    'seller_id' => $property->user_id, 
                    'agency_id' => $property->agency_id ?? 1,
                    'amount' => $data['amount'],
                    'total_paid' => 0.00,
                    'remaining' => $data['amount'],
                    'is_fully_funded' => false,
                    'terms' => $data['terms'],
                    'status' => 'pending_funding',
                    'created_by' => Auth::id(),
                ]);

                return response()->json($escrow, 201);
            });
        } catch (Exception $e) {
            Log::error('Escrow creation failed', ['error' => $e->getMessage(), 'data' => $data]);
            return response()->json(['message' => 'Failed to initialize escrow workspace'], 500);
        }
    }

    public function show($id)
    {
        $user = Auth::user();
        $escrow = Escrow::with(['milestones', 'payments', 'property', 'buyer', 'seller'])->findOrFail($id);

        if (($user->role ?? '') !== 'admin' && $escrow->buyer_id !== $user->id && $escrow->seller_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized workspace access'], 403);
        }

        return response()->json([
            'escrow' => $escrow,
            'progress' => $escrow->amount > 0 ? round(($escrow->total_paid / $escrow->amount) * 100, 2) : 0,
            'total_paid' => $escrow->total_paid,
            'remaining' => $escrow->remaining,
            'is_fully_funded' => $escrow->is_fully_funded,
        ]);
    }

    /**
     * Process absolute incoming payment tracking directly linked to a Paystack ledger allocation credit.
     */
    public function recordFundingAllocation(Request $request, $id)
    {
        $validated = $request->validate([
            'reference' => 'required|string',
            'amount_paid' => 'required|numeric|min:1'
        ]);

        try {
            return DB::transaction(function () use ($validated, $id) {
                $escrow = Escrow::lockForUpdate()->findOrFail($id);
                
                // Confirm tracking parameters against the Paystack validation endpoint
                $paymentVerification = $this->paystack->verifyTransaction($validated['reference']);
                
                if ($paymentVerification['status'] !== 'success') {
                    return response()->json(['message' => 'Paystack transaction reference is unverified'], 422);
                }

                $newTotalPaid = $escrow->total_paid + $validated['amount_paid'];
                $newRemaining = max(0, $escrow->amount - $newTotalPaid);
                $isFullyFunded = $newTotalPaid >= $escrow->amount;

                $escrow->update([
                    'total_paid' => $newTotalPaid,
                    'remaining' => $newRemaining,
                    'is_fully_funded' => $isFullyFunded,
                    'status' => $isFullyFunded ? 'funded' : 'partially_funded'
                ]);

                // Append reference context to platform logs
                DB::table('transaction_logs')->insert([
                    'escrow_id' => $escrow->id,
                    'action' => 'PAYSTACK_FUNDS_CREDITED',
                    'amount' => $validated['amount_paid'] * 100, // Normalized to minor units
                    'payload_snapshot' => json_encode($paymentVerification),
                    'created_at' => now()
                ]);

                return response()->json(['success' => true, 'escrow' => $escrow]);
            });
        } catch (Exception $e) {
            Log::error('Escrow funding mapping exception', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Inbound pipeline processing failure'], 500);
        }
    }

    public function addMilestone(Request $request, $escrowId)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'amount' => 'required|numeric|min:1',
            'description' => 'nullable|string',
        ]);

        try {
            return DB::transaction(function () use ($data, $escrowId) {
                $escrow = Escrow::lockForUpdate()->findOrFail($escrowId);

                $currentMilestonesSum = $escrow->milestones()->sum('amount');
                if (($currentMilestonesSum + $data['amount']) > $escrow->amount) {
                    return response()->json(['message' => 'Cumulative milestone totals exceed global escrow contractual amount'], 422);
                }

                $milestone = $escrow->milestones()->create([
                    'name' => $data['name'],
                    'description' => $data['description'],
                    'amount' => $data['amount'],
                    'status' => 'pending',
                ]);

                return response()->json($milestone, 201);
            });
        } catch (Exception $e) {
            Log::error('Milestone attachment failure', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Could not attach metric milestone allocation'], 500);
        }
    }

    public function approveMilestone($id)
    {
        try {
            return DB::transaction(function () use ($id) {
                $milestone = EscrowMilestone::with('escrow')->lockForUpdate()->findOrFail($id);
                $escrow = $milestone->escrow;
                $user = Auth::user();

                if ($user->id !== $escrow->buyer_id) {
                    return response()->json(['message' => 'Only the purchasing entity can approve milestone progress'], 403);
                }

                if ($milestone->status !== 'pending') {
                    return response()->json(['message' => 'Milestone status state is unalterable'], 422);
                }

                if ($escrow->status === 'disputed') {
                    return response()->json(['message' => 'Cannot clear milestone allocations while pipeline is locked under dispute'], 422);
                }

                // Locate structural Paystack clearance recipient profile mapping for target user
                $sellerProfile = DB::table('seller_payment_profiles')
                    ->where('user_id', $escrow->seller_id)
                    ->first();

                if (!$sellerProfile || !$sellerProfile->paystack_recipient_code) {
                    return response()->json(['message' => 'Seller payout channel routing configurations not initialized on Paystack rails'], 422);
                }

                // Convert amount to Paystack minor unit integers (cents/pesewas)
                $minorUnitAmount = (int)($milestone->amount * 100);

                // Execute transfer on live payment infrastructure balance
                $payoutResponse = $this->paystack->initiateTransfer(
                    $minorUnitAmount,
                    $sellerProfile->paystack_recipient_code,
                    "Milestone Clear Reference ID: #{$milestone->id}"
                );

                // Update structural metrics upon successful clearing hook
                $milestone->update([
                    'status' => 'approved',
                    'approved_at' => now(),
                    'approved_by' => $user->id,
                ]);

                DB::table('transaction_logs')->insert([
                    'escrow_id' => $escrow->id,
                    'action' => 'MILESTONE_PAYSTACK_DISBURSAL_SUCCESS',
                    'amount' => $minorUnitAmount,
                    'payload_snapshot' => json_encode($payoutResponse),
                    'created_at' => now()
                ]);

                // Check if all existing milestone vectors are completed to automatically close out contract status
                $totalMilestonesCount = $escrow->milestones()->count();
                $approvedMilestonesCount = $escrow->milestones()->where('status', 'approved')->count();

                if ($totalMilestonesCount === $approvedMilestonesCount && $escrow->is_fully_funded) {
                    $escrow->update(['status' => 'completed']);
                }
                $this->activity->log(
                    auth()->id(),
                    "Milestone approved and payout processed for escrow: {$escrow->id}"
                );

                return response()->json(['success' => true, 'milestone' => $milestone, 'escrow_status' => $escrow->status]);
            });
        } catch (Exception $e) {
            Log::error('Milestone approval and payout exception', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Approval verification mapping execution failure: ' . $e->getMessage()], 500);
        }
    }

    public function raiseDispute(Request $request, $id)
    {
        $data = $request->validate([
            'reason' => 'required|string|max:255',
            'description' => 'required|string',
        ]);

        try {
            return DB::transaction(function () use ($data, $id) {
                $escrow = Escrow::lockForUpdate()->findOrFail($id);
                $user = Auth::user();

                if ($escrow->buyer_id !== $user->id && $escrow->seller_id !== $user->id) {
                    return response()->json(['message' => 'Access denied to active escrow parameters'], 403);
                }

                if (in_array($escrow->status, ['completed', 'cancelled'])) {
                    return response()->json(['message' => 'Cannot flag completed agreements for tracking disputes'], 422);
                }

                $dispute = $escrow->disputes()->create([
                    'raised_by_user_id' => $user->id,
                    'reason' => $data['reason'],
                    'description' => $data['description'],
                    'status' => 'pending',
                ]);

                $escrow->update(['status' => 'disputed']);

                DB::table('transaction_logs')->insert([
                    'escrow_id' => $escrow->id,
                    'action' => 'ARBITRATION_NODE_TRIGGERED',
                    'amount' => 0,
                    'payload_snapshot' => json_encode(['triggered_by' => $user->id, 'dispute_id' => $dispute->id]),
                    'created_at' => now()
                ]);

                $this->activity->log(
                    auth()->id(),
                    "Dispute raised for escrow: {$escrow->id}"
                );

                return response()->json($dispute, 201);
            });
        } catch (Exception $e) {
            Log::error('Dispute initial processing failure', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Could not register infrastructure conflict log'], 500);
        }
    }
}