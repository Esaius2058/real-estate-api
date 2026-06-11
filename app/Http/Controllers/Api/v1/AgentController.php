<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Http\Requests\Agency\StoreAgentRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache; // Add Cache Facade

class AgentController extends Controller
{
    public function index(): JsonResponse
    {
        $agencyId = auth()->user()->agency_id;

        // Cache the agents list for this specific agency for 60 minutes
        $agents = Cache::remember("agency_{$agencyId}_agents", now()->addMinutes(60), function () {
            return User::where('role', 'agent')->get(); 
            // Note: Global scope restricts this to the auth user's agency inside the model
        });
        
        return response()->json(['data' => $agents]);
    }

    public function store(StoreAgentRequest $request): JsonResponse
    {
        $agencyId = auth()->user()->agency_id;

        $agent = User::create([
            'agency_id' => $agencyId,
            'name'      => $request->name,
            'email'     => $request->email,
            'password'  => bcrypt($request->password),
            'role'      => 'agent',
        ]);

        // CRITICAL: Clear the agent list cache
        Cache::forget("agency_{$agencyId}_agents");

        return response()->json(['data' => $agent], 201);
    }

    public function update(Request $request, User $agent): JsonResponse // Assuming Request or UpdateAgentRequest
    {
        $validated = $request->validated();

        if (!empty($validated['password'])) {
            $validated['password'] = bcrypt($validated['password']);
        } else {
            unset($validated['password']);
        }

        $agent->update($validated);

        // CRITICAL: Clear the agent list cache
        Cache::forget("agency_{$agent->agency_id}_agents");

        return response()->json(['data' => $agent]);
    }

    public function destroy(User $agent): JsonResponse
    {
        $agencyId = auth()->user()->agency_id;

        if ($agent->agency_id !== $agencyId) {
            return response()->json(['message' => 'Unauthorized operation.'], 403);
        }

        $agent->delete();

        // CRITICAL: Clear the agent list cache
        Cache::forget("agency_{$agencyId}_agents");

        return response()->json(null, 204); 
    }
}