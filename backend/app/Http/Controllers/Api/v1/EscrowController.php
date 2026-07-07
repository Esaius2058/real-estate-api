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

    public function __construct(PaystackService $paystack)
    {
        $this->paystack = $paystack;
    }

    /**
     * List all escrows for the authenticated user
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $query = Escrow::with(['property', 'buyer', 'seller']);

        if (($user->role ?? '') !== 'admin') {
            $query->where(function ($q) use ($user) {
                $q->where('buyer_id', $user->id)
                  ->orWhere('seller_id', $user->id);
            });
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $escrows = $query->latest()->get()->map(function ($escrow) {
            return [
                'id'              => $escrow->id,
                'propertyTitle'   => $escrow->property?->title ?? 'Unknown Property',
                'amount'          => $escrow->amount,
                'total_paid'      => $escrow->total_paid,
                'remaining'       => $escrow->remaining,
                'status'          => $escrow->status,
                'terms'           => $escrow->terms,
                'buyer_id'        => $escrow->buyer_id,
                'seller_id'       => $escrow->seller_id,
                'is_fully_funded' => $escrow->is_fully_funded,
                'updated_at'      => $escrow->updated_at,
            ];
        });

        return response()->json($escrows);
    }

    /**
     * Create a new escrow agreement
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'property_id' => 'required|exists:properties,id',
            'amount'      => 'required|numeric|min:1',
            'terms'       => 'nullable|string',
        ]);

        try {
            return DB::transaction(function () use ($data) {
                $property = Property::findOrFail($data['property_id']);

                $escrow = Escrow::create([
                    'property_id'     => $property->id,
                    'buyer_id'        => Auth::id(),
                    'seller_id'       => $property->user_id,
                    'agency_id'       => $property->agency_id ?? 1,
                    'amount'          => $data['amount'],
                    'total_paid'      => 0.00,
                    'remaining'       => $data['amount'],
                    'is_fully_funded' => false,
                    'terms'           => $data['terms'] ?? null,
                    'status'          => 'pending_funding',
                    'created_by'      => Auth::id(),
                ]);

                return response()->json([
                    'message' => 'Escrow created successfully.',
                    'escrow'  => $escrow->load('property'),
                ], 201);
            });
        } catch (Exception $e) {
            Log::error('Escrow creation failed', ['error' => $e->getMessage(), 'data' => $data]);
            return response()->json(['message' => 'Failed to initialize escrow.'], 500);
        }
    }

    /**
     * Get single escrow with progress details
     */
    public function show($id)
    {
        $user = Auth::user();
        $escrow = Escrow::with(['milestones', 'payments', 'property', 'buyer', 'seller'])->findOrFail($id);

        if (($user->role ?? '') !== 'admin' && $escrow->buyer_id !== $user->id && $escrow->seller_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized workspace access.'], 403);
        }

        return response()->json([
            'escrow'          => $escrow,
            'progress'        => $escrow->amount > 0
                                    ? round(($escrow->total_paid / $escrow->amount) * 100, 2)
                                    : 0,
            'total_paid'      => $escrow->total_paid,
            'remaining'       => $escrow->remaining,
            'is_fully_funded' => $escrow->is_fully_funded,
        ]);
    }

    public function myEscrows(Request $request)
    {
        $user = Auth::user();
        $limit = (int) $request->query('limit', 20);

        $escrows = Escrow::with(['property', 'buyer', 'seller'])
            ->where(function ($q) use ($user) {
                $q->where('buyer_id', $user->id)
                  ->orWhere('seller_id', $user->id);
            })
            ->latest()
            ->limit($limit)
            ->get()->map(function ($escrow) {
                return [
                    'id'              => $escrow->id,
                    'propertyTitle'   => $escrow->property?->title ?? 'Unknown Property',
                    'amount'          => $escrow->amount,
                    'total_paid'      => $escrow->total_paid,
                    'remaining'       => $escrow->remaining,
                    'status'          => $escrow->status,
                    'terms'           => $escrow->terms,
                    'buyer_id'        => $escrow->buyer_id,
                    'seller_id'       => $escrow->seller_id,
                    'is_fully_funded' => $escrow->is_fully_funded,
                    'updated_at'      => $escrow->updated_at,
                ];
            });

        return response()->json($escrows);
    }

    /**
     * Buyer releases funds to seller after conditions are met
     * POST /api/v1/escrows/{id}/release
     */
    public function release(Request $request, $id)
    {
        try {
            return DB::transaction(function () use ($id) {
                $escrow = Escrow::lockForUpdate()->findOrFail($id);
                $user = Auth::user();

                if ($user->id !== $escrow->buyer_id && ($user->role ?? '') !== 'admin') {
                    return response()->json(['message' => 'Only the buyer can release funds.'], 403);
                }

                if ($escrow->status === 'completed') {
                    return response()->json(['message' => 'Escrow already completed.'], 422);
                }

                if (!in_array($escrow->status, ['held', 'inspection', 'funded'])) {
                    return response()->json([
                        'message' => 'Funds can only be released when escrow is held, funded, or in inspection.'
                    ], 422);
                }

                $escrow->update(['status' => 'completed']);

                DB::table('transaction_logs')->insert([
            'escrow_id'  => $escrow->id,
            'event'      => 'GLOBAL_ESCROW_RELEASE',
            'payload'    => json_encode(['released_by' => $user->id]),
            'amount'     => $escrow->total_paid * 100,
            'created_at' => now(),
]);

                // TODO: PayoutService::disburse($escrow->seller_id, $escrow->total_paid);

                Log::info('Escrow funds released', [
                    'escrow_id' => $escrow->id,
                    'buyer_id'  => $escrow->buyer_id,
                    'seller_id' => $escrow->seller_id,
                    'amount'    => $escrow->total_paid,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Funds released to seller successfully.',
                    'escrow'  => $escrow->fresh(),
                ]);
            });
        } catch (Exception $e) {
            Log::error('Escrow release failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to release funds.'], 500);
        }
    }

    /**
     * Refund buyer if conditions are not met
     * POST /api/v1/escrows/{id}/refund
     */
    public function refund(Request $request, $id)
    {
        try {
            return DB::transaction(function () use ($id) {
                $escrow = Escrow::lockForUpdate()->findOrFail($id);
                $user = Auth::user();

                if (($user->role ?? '') !== 'admin' && $user->id !== $escrow->seller_id) {
                    return response()->json(['message' => 'Only the seller or admin can initiate a refund.'], 403);
                }

                if ($escrow->status === 'refunded' || $escrow->status === 'cancelled') {
                    return response()->json(['message' => 'Escrow already refunded.'], 422);
                }

                $escrow->update(['status' => 'refunded']);

                DB::table('transaction_logs')->insert([
                    'escrow_id'        => $escrow->id,
                    'event'           => 'ESCROW_CAPITAL_REFUNDED',
                    'amount'           => $escrow->total_paid * 100,
                    'payload' => json_encode(['refunded_by' => $user->id]),
                    'created_at'       => now(),
                ]);

                // TODO: RefundService::refund($escrow->buyer_id, $escrow->total_paid);

                Log::info('Escrow refunded', [
                    'escrow_id' => $escrow->id,
                    'buyer_id'  => $escrow->buyer_id,
                    'amount'    => $escrow->total_paid,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Escrow refunded to buyer successfully.',
                    'escrow'  => $escrow->fresh(),
                ]);
            });
        } catch (Exception $e) {
            Log::error('Escrow refund failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to process refund.'], 500);
        }
    }

    /**
     * Seller signals delivery and requests buyer confirmation
     * POST /api/v1/escrows/{id}/request-inspection
     */
    public function requestInspection($id)
    {
        try {
            return DB::transaction(function () use ($id) {
                $escrow = Escrow::lockForUpdate()->findOrFail($id);
                $user = Auth::user();

                if ($user->id !== $escrow->seller_id) {
                    return response()->json(['message' => 'Only the seller can request inspection.'], 403);
                }

                if (!in_array($escrow->status, ['held', 'funded', 'partially_funded'])) {
                    return response()->json(['message' => 'Escrow must be funded before requesting inspection.'], 422);
                }

                $escrow->update(['status' => 'inspection']);

                DB::table('transaction_logs')->insert([
                    'escrow_id'        => $escrow->id,
                    'event'           => 'INSPECTION_REQUESTED',
                    'amount'           => 0,
                    'payload' => json_encode(['requested_by' => $user->id]),
                    'created_at'       => now(),
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Inspection requested. Buyer has been notified to review and release funds.',
                    'escrow'  => $escrow->fresh(),
                ]);
            });
        } catch (Exception $e) {
            Log::error('Inspection request failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to request inspection.'], 500);
        }
    }

    /**
     * Record incoming Paystack payment against escrow
     */
    public function recordFundingAllocation(Request $request, $id)
    {
        $validated = $request->validate([
            'reference'  => 'required|string',
            'amount_paid' => 'required|numeric|min:1',
        ]);

        try {
            return DB::transaction(function () use ($validated, $id) {
                $escrow = Escrow::lockForUpdate()->findOrFail($id);

                $paymentVerification = $this->paystack->verifyTransaction($validated['reference']);

                if ($paymentVerification['status'] !== 'success') {
                    return response()->json(['message' => 'Paystack transaction reference is unverified.'], 422);
                }

                $newTotalPaid = $escrow->total_paid + $validated['amount_paid'];
                $newRemaining = max(0, $escrow->amount - $newTotalPaid);
                $isFullyFunded = $newTotalPaid >= $escrow->amount;

                $escrow->update([
                    'total_paid'      => $newTotalPaid,
                    'remaining'       => $newRemaining,
                    'is_fully_funded' => $isFullyFunded,
                    'status'          => $isFullyFunded ? 'funded' : 'partially_funded',
                ]);

                DB::table('transaction_logs')->insert([
                    'escrow_id'        => $escrow->id,
                    'event'           => 'PAYSTACK_FUNDS_CREDITED',
                    'amount'           => $validated['amount_paid'] * 100,
                    'payload' => json_encode($paymentVerification),
                    'created_at'       => now(),
                ]);

                return response()->json(['success' => true, 'escrow' => $escrow]);
            });
        } catch (Exception $e) {
            Log::error('Escrow funding mapping exception', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Payment processing failure.'], 500);
        }
    }

    /**
     * Add a milestone to an escrow
     */
    public function addMilestone(Request $request, $escrowId)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:255',
            'amount'      => 'required|numeric|min:1',
            'description' => 'nullable|string',
        ]);

        try {
            return DB::transaction(function () use ($data, $escrowId) {
                $escrow = Escrow::lockForUpdate()->findOrFail($escrowId);

                $currentMilestonesSum = $escrow->milestones()->sum('amount');
                if (($currentMilestonesSum + $data['amount']) > $escrow->amount) {
                    return response()->json(['message' => 'Milestone total exceeds escrow amount.'], 422);
                }

                $milestone = $escrow->milestones()->create([
                    'name'        => $data['name'],
                    'description' => $data['description'] ?? null,
                    'amount'      => $data['amount'],
                    'status'      => 'pending',
                ]);

                return response()->json($milestone, 201);
            });
        } catch (Exception $e) {
            Log::error('Milestone attachment failure', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Could not attach milestone.'], 500);
        }
    }

    /**
     * Buyer approves a milestone and triggers Paystack payout to seller
     */
    public function approveMilestone($id)
    {
        try {
            return DB::transaction(function () use ($id) {
                $milestone = EscrowMilestone::with('escrow')->lockForUpdate()->findOrFail($id);
                $escrow = $milestone->escrow;
                $user = Auth::user();

                if ($user->id !== $escrow->buyer_id) {
                    return response()->json(['message' => 'Only the buyer can approve milestones.'], 403);
                }

                if ($milestone->status !== 'pending') {
                    return response()->json(['message' => 'Milestone already processed.'], 422);
                }

                if ($escrow->status === 'disputed') {
                    return response()->json(['message' => 'Cannot approve milestones while escrow is disputed.'], 422);
                }

                $sellerProfile = DB::table('seller_payment_profiles')
                    ->where('user_id', $escrow->seller_id)
                    ->first();

                if (!$sellerProfile || !$sellerProfile->paystack_recipient_code) {
                    return response()->json(['message' => 'Seller payout profile not configured on Paystack.'], 422);
                }

                $minorUnitAmount = (int)($milestone->amount * 100);

                $payoutResponse = $this->paystack->initiateTransfer(
                    $minorUnitAmount,
                    $sellerProfile->paystack_recipient_code,
                    "Milestone #{$milestone->id} release"
                );

                $milestone->update([
                    'status'      => 'approved',
                    'approved_at' => now(),
                    'approved_by' => $user->id,
                ]);

                DB::table('transaction_logs')->insert([
                    'escrow_id'        => $escrow->id,
                    'event'           => 'MILESTONE_PAYSTACK_DISBURSAL_SUCCESS',
                    'amount'           => $minorUnitAmount,
                    'payload' => json_encode($payoutResponse),
                    'created_at'       => now(),
                ]);

                $totalCount    = $escrow->milestones()->count();
                $approvedCount = $escrow->milestones()->where('status', 'approved')->count();

                if ($totalCount === $approvedCount && $escrow->is_fully_funded) {
                    $escrow->update(['status' => 'completed']);
                }

                return response()->json([
                    'success'       => true,
                    'milestone'     => $milestone,
                    'escrow_status' => $escrow->fresh()->status,
                ]);
            });
        } catch (Exception $e) {
            Log::error('Milestone approval failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Milestone approval failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Raise a dispute on an escrow
     */
    public function raiseDispute(Request $request, $id)
    {
        $data = $request->validate([
            'reason'      => 'required|string|max:255',
            'description' => 'required|string',
        ]);

        try {
            return DB::transaction(function () use ($data, $id) {
                $escrow = Escrow::lockForUpdate()->findOrFail($id);
                $user = Auth::user();

                if ($escrow->buyer_id !== $user->id && $escrow->seller_id !== $user->id) {
                    return response()->json(['message' => 'Unauthorized.'], 403);
                }

                if (in_array($escrow->status, ['completed', 'cancelled', 'refunded'])) {
                    return response()->json(['message' => 'Cannot dispute a closed escrow.'], 422);
                }

                $dispute = $escrow->disputes()->create([
                    'raised_by_user_id' => $user->id,
                    'reason'            => $data['reason'],
                    'description'       => $data['description'],
                    'status'            => 'pending',
                ]);

                $escrow->update(['status' => 'disputed']);

                DB::table('transaction_logs')->insert([
    'escrow_id'  => $escrow->id,
    'event'      => 'ARBITRATION_NODE_TRIGGERED',
    'payload'    => json_encode(['triggered_by' => $user->id, 'dispute_id' => $dispute->id]),
    'amount'     => 0,
    'created_at' => now(),
]);

                return response()->json($dispute, 201);
            });
        } catch (Exception $e) {
            Log::error('Dispute creation failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Could not register dispute.'], 500);
        }
    }

    /**
     * Verify a Paystack payment reference
     * GET /api/v1/escrows/verify/{reference}
     */
public function verifyPayment($reference)
{
    try {
        Log::info('Verify called', ['reference' => $reference]);
        
        $paymentVerification = $this->paystack->verifyTransaction($reference);
        Log::info('Paystack response', ['status' => $paymentVerification['status'] ?? 'none']);

        if (!($paymentVerification['status'] ?? false) || ($paymentVerification['data']['status'] ?? '') !== 'success') {
            Log::error('Paystack verify failed status check');
            return response()->json(['message' => 'Transaction verification failed.'], 422);
        }

        $metadata   = $paymentVerification['data']['metadata'] ?? [];
        $escrowId   = $metadata['escrow_id'] ?? null;
        $amountPaid = $paymentVerification['data']['amount'] / 100;

        Log::info('Escrow ID from metadata', ['escrow_id' => $escrowId, 'amount' => $amountPaid]);

        if (!$escrowId) {
            Log::error('No escrow ID in metadata');
            return response()->json(['message' => 'Payment metadata missing escrow reference.'], 422);
        }

        $escrow = Escrow::findOrFail($escrowId);
        Log::info('Escrow found', ['id' => $escrow->id]);

        \App\Models\Payment::create([
            'escrow_id'    => $escrow->id,
            'user_id'      => Auth::id() ?? 1,
            'agency_id'    => $escrow->agency_id ?? 1,
            'amount'       => $amountPaid,
            'status'       => 'completed',
            'payment_type' => 'escrow',
            'payment_method' => 'paystack_card',
            'transaction_reference' => $reference,
            'paid_at'      => now(),
            'currency'     => 'KES',
        ]);

        Log::info('Payment created');

        $newTotalPaid  = $escrow->total_paid + $amountPaid;
        $newRemaining  = max(0, $escrow->amount - $newTotalPaid);
        $isFullyFunded = $newTotalPaid >= $escrow->amount;

        $escrow->update([
            'total_paid'      => $newTotalPaid,
            'remaining'       => $newRemaining,
            'is_fully_funded' => $isFullyFunded,
            'status'          => $isFullyFunded ? 'funded' : 'partially_funded',
        ]);

        Log::info('Escrow updated', ['total_paid' => $newTotalPaid]);

        return response()->json([
            'success'     => true,
            'amount_paid' => $amountPaid,
            'escrow'      => $escrow->fresh(),
        ]);

    } catch (Exception $e) {
        Log::error('Paystack verification failed', ['error' => $e->getMessage()]);
        return response()->json(['message' => 'Verification failed.'], 500);
    }
}
    /**
     * Get timeline logs for an escrow
     * GET /api/v1/escrows/{id}/timeline
     */
    public function timeline($id)
    {
        $escrow = Escrow::findOrFail($id);
        $user = Auth::user();

        if ($user->id !== $escrow->buyer_id && $user->id !== $escrow->seller_id && ($user->role ?? '') !== 'admin') {
            return response()->json(['message' => 'Unauthorized timeline access.'], 403);
        }

        $logs = DB::table('transaction_logs')
            ->where('escrow_id', $id)
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json($logs);
    }
    public function initializeDeposit(Request $request)
{
    $request->validate([
        'amount'    => 'required|numeric|min:1',
        'escrowId'  => 'required|exists:escrows,id',
    ]);

    try {
        $escrow = Escrow::findOrFail($request->escrowId);
        $user   = Auth::user();

        $amountInMinorUnits = intval(round($request->amount * 100));

        $result = $this->paystack->initializeTransaction(
            $user->email,
            $amountInMinorUnits,
            ['escrow_id' => $escrow->id],
            null
        );

        if ($result && isset($result['data']['authorization_url'])) {
            return response()->json([
                'success'    => true,
                'paymentUrl' => $result['data']['authorization_url'],
                'reference'  => $result['data']['reference'] ?? null,
            ]);
        }

        return response()->json(['message' => 'Failed to initialize deposit.'], 500);

    } catch (\Exception $e) {
        Log::error('Deposit initialization failed', ['error' => $e->getMessage()]);
        return response()->json(['message' => $e->getMessage()], 500);
    }
}
}