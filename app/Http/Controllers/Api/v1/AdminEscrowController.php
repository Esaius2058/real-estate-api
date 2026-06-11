<?php

namespace App\Http\Controllers\Api\v1; // ✅ Fixed backslash

use App\Http\Controllers\Controller;
use App\Models\EscrowDispute;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth; // ✅ Added explicitly

class AdminEscrowController extends Controller
{
    public function index()
    {
        $totalVolume = DB::table('escrows')->where('status', 'funded')->sum('amount') ?? 0;

        return response()->json([
            'total_locked_volume' => (float) $totalVolume,
            'disputes_count' => EscrowDispute::where('status', 'open')->count(),
            'disputes' => EscrowDispute::with(['accuser'])->where('status', 'open')->get()
        ]);
    }

    public function resolveDispute(Request $request, $id)
    {
        $request->validate([
            'action' => 'required|in:refund_to_buyer,released_to_seller',
            'notes' => 'required|string|min:10'
        ]);

        $dispute = EscrowDispute::findOrFail($id);
        $escrow = DB::table('escrows')->where('id', $dispute->escrow_id);

        DB::transaction(function () use ($dispute, $escrow, $request) {
            if ($request->action === 'refund_to_buyer') {
                $escrow->update(['status' => 'refunded', 'updated_at' => now()]);
                $dispute->resolution = 'refunded_to_buyer';
            } else {
                $escrow->update(['status' => 'completed', 'updated_at' => now()]);
                $dispute->resolution = 'released_to_seller';
            }
            
            $dispute->status = 'resolved';
            $dispute->admin_notes = $request->notes;
            $dispute->resolved_by_id = Auth::id(); // ✅ Cleaned up for Intelephense
            $dispute->save();
        });

        return response()->json(['message' => 'Transaction dispute record successfully settled by admin rule.']);
    }
}