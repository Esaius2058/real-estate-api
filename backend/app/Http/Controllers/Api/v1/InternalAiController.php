<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\Property;
use Illuminate\Http\JsonResponse;

class InternalAiController extends Controller
{
    /**
     * Fetch new leads for a specific agency context.
     */
    public function getLeads(string $agencyId): JsonResponse
    {
        $leads = Lead::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where('kanban_stage', 'new')
            ->get();

        return response()->json(['data' => $leads]);
    }

    /**
     * Fetch active properties for a specific agency context.
     */
    public function getProperties(string $agencyId): JsonResponse
    {
        // Fixed: Matching database Enum case ('Active')
        $properties = Property::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where('status', 'Active') 
            ->get();

        return response()->json(['data' => $properties]);
    }
}