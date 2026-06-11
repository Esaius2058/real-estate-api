<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\User;
use App\Http\Requests\Agency\UpdateAgencyRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache; // Add Cache Facade
use Illuminate\Support\Str;

class AgencyController extends Controller
{
    /**
     * GET /v1/agency
     * Returns the authenticated user's agency.
     */
    public function show(): JsonResponse
    {
        $agencyId = auth()->user()->agency_id;

        if (!$agencyId) {
            return response()->json(['message' => 'Agency not found.'], 404);
        }

        // Cache the agency data for 60 minutes
        $agency = Cache::remember("agency_{$agencyId}", now()->addMinutes(60), function () use ($agencyId) {
            return Agency::find($agencyId);
        });

        if (!$agency) {
            return response()->json(['message' => 'Agency not found.'], 404);
        }

        return response()->json(['data' => $agency]);
    }

    /**
     * POST /v1/vault/initialize-workspace
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'agency_name' => ['required', 'string', 'max:255'],
            'role'        => ['required', 'in:Admin,Agent'],
        ]);

        $user = auth()->user();

        if ($user->agency_id) {
            return response()->json(['message' => 'You are already assigned to an agency.'], 409);
        }

        $agency = Agency::create([
            'name'      => $validated['agency_name'],
            'join_code' => strtoupper(Str::random(4)) . '-' . rand(1000, 9999),
        ]);

        $user->update([
            'agency_id' => $agency->id,
            'role'      => strtolower($validated['role']),
        ]);

        // No cache invalidation needed here since this is a brand new agency

        return response()->json([
            // ... (keep your existing response mapping) ...
            'agency' => $agency,
        ], 201);
    }

    /**
     * POST /v1/agency/join
     */
    public function join(Request $request): JsonResponse
    {
        // ... (keep your existing join logic) ...
        
        $agency = Agency::where('join_code', strtoupper($validated['join_code']))->firstOrFail();

        $user->update([
            'agency_id' => $agency->id,
            'role'      => 'agent',
        ]);

        // Clear the agents list cache for this agency since a new user joined
        Cache::forget("agency_{$agency->id}_agents");

        return response()->json([
            // ... (keep your existing response mapping) ...
        ]);
    }

    /**
     * PUT /v1/agency/{agency}
     */
    public function update(UpdateAgencyRequest $request, Agency $agency): JsonResponse
    {
        $this->authorize('update', $agency);

        $agency->update($request->validated());

        // CRITICAL: Invalidate the agency cache
        Cache::forget("agency_{$agency->id}");

        return response()->json(['data' => $agency->fresh()]);
    }
}