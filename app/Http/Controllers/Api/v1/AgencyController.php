<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Http\Requests\Agency\UpdateAgencyRequest;
use Illuminate\Http\JsonResponse;

class AgencyController extends Controller
{
    public function show(): JsonResponse
    {
        // Return the authenticated user's agency
        $agency = Agency::find(auth()->user()->agency_id);
        
        if (!$agency) {
            return response()->json(['message' => 'Agency not found'], 404);
        }

        return response()->json(['data' => $agency]);
    }

    public function update(UpdateAgencyRequest $request, Agency $agency): JsonResponse
    {
        $agency->update($request->validated());

        return response()->json(['data' => $agency]);
    }
}