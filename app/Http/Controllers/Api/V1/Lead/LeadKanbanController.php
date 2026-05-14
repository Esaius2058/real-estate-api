<?php

namespace App\Http\Controllers\Api\V1\Lead;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class LeadKanbanController extends Controller
{
    public function update(Request $request, Lead $lead): JsonResponse
    {
        $validated = $request->validate([
            'kanban_stage' => 'required|string|in:new,contacted,showing,offer,escrow,closed,lost'
        ]);

        $lead->update(['kanban_stage' => $validated['kanban_stage']]);

        // Trigger events here if needed (e.g., LeadMovedToEscrow Event)

        return response()->json([
            'message' => 'Stage updated successfully',
            'data'    => $lead
        ]);
    }
}