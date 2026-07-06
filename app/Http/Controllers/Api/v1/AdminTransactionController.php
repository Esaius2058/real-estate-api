<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;

class AdminTransactionController extends Controller
{
    /**
     * GET /api/v1/admin/transactions
     */
    public function index(Request $request)
    {
        $filters = $request->only(['status', 'payment_method', 'payment_type']);

        // FIXED: Replaced the breaking soft-delete closure with a clean relationship array
        $query = Payment::with(['user', 'escrow', 'subscription'])->latest();

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['payment_method'])) {
            $query->where('payment_method', $filters['payment_method']);
        }
        if (!empty($filters['payment_type'])) {
            $query->where('payment_type', $filters['payment_type']);
        }

        return response()->json($query->paginate(20));
    }

    /**
     * PATCH /api/v1/admin/transactions/{payment}/status
     */
    public function updateStatus(Request $request, Payment $payment)
    {
        $validated = $request->validate([
            'status' => 'required|in:completed,failed',
        ]);

        $oldStatus = $payment->status;
        $newStatus = $validated['status'];

        if ($oldStatus === $newStatus) {
            return response()->json([
                'success' => false,
                'message' => "Payment is already in status: {$newStatus}",
            ], 422);
        }

        DB::transaction(function () use ($payment, $oldStatus, $newStatus) {
            $payment->update([
                'status' => $newStatus,
                'paid_at' => $newStatus === 'completed' ? now() : null,
            ]);

            if ($newStatus === 'completed') {
                if ($payment->subscription_id) {
                    \App\Models\Subscription::activateFromPaymentId($payment->subscription_id);
                }

                if ($payment->escrow_id && $payment->escrow) {
                    $payment->escrow->applyPayment();
                }
            }

            // Log manual change to transaction_logs
            DB::table('transaction_logs')->insert([
                'payment_id' => $payment->id,
                'event' => 'manual_status_update',
                'payload' => json_encode([
                    'previous_status' => $oldStatus,
                    'new_status' => $newStatus,
                    'admin_id' => \Illuminate\Support\Facades\Auth::id() 
                ]),
                'escrow_id' => $payment->escrow_id,
                'action' => 'MANUAL_STATUS_UPDATE',
                'amount' => (int) round($payment->amount),
                'payload_snapshot' => json_encode(['payment' => $payment->toArray()]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
    }); 

        return response()->json([
            'success' => true,
            'message' => "Payment status updated to {$newStatus}.",
            'payment' => $payment->fresh(),
        ]);
    }

    /**
     * GET /api/v1/admin/transactions/export
     */
    public function export(Request $request)
    {
        $filters = $request->only(['status', 'payment_method', 'payment_type']);

        $query = Payment::latest();

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['payment_method'])) {
            $query->where('payment_method', $filters['payment_method']);
        }
        if (!empty($filters['payment_type'])) {
            $query->where('payment_type', $filters['payment_type']);
        }

        $transactions = Payment::latest()->get();

        $metrics = [
            'total_volume' => $transactions->where('status', 'completed')->sum('amount'),
            'processed_count' => $transactions->count(),
            'successful_count' => $transactions->where('status', 'completed')->count(),
            'generated_at' => now()->timezone('Africa/Nairobi')->format('d M Y H:i:s'),
        ];
        
        // FIXED: Removed early JSON return debug statement so the PDF layout can compile cleanly
        $pdf = Pdf::loadView('exports.transactions_pdf', compact('transactions', 'metrics'));
        $pdf->setPaper('a4', 'portrait');
        return $pdf->stream('MAKAO-Financial-Statement-' . now()->format('Y-m-d') . '.pdf');
    }
}