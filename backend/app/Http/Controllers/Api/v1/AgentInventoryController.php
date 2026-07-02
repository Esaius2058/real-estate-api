<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Property;

class AgentInventoryController extends Controller
{
    public function getProperties($agencyId, Request $request)
    {
        // Fetch active properties for the specific agency
        $properties = Property::where('agency_id', $agencyId)
            ->where('status', 'active') // Adjust based on your DB schema
            ->get([
                'id', 'bedrooms', 'baths', 'sqft', 'price', 
                'type', 'location', 'city', 'description'
            ]);

        return response()->json([
            'data' => $properties
        ]);
    }
}