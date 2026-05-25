<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Http\Requests\Agency\StoreAgentRequest;
use Illuminate\Http\JsonResponse;

class AgentController extends Controller
{
    public function index(): JsonResponse
    {
        // Global scope restricts this to the authenticated user's agency
        $agents = User::where('role', 'agent')->get();
        
        return response()->json(['data' => $agents]);
    }

    public function store(StoreAgentRequest $request): JsonResponse
    {
        $agent = User::create([
            'agency_id' => auth()->user()->agency_id,
            'name'      => $request->name,
            'email'     => $request->email,
            'password'  => bcrypt($request->password),
            'role'      => 'agent',
        ]);

        return response()->json(['data' => $agent], 201);
    }

    public function update(UpdateAgentRequest $request, User $agent): JsonResponse
    {
        $validated = $request->validated();

        // Only hash and update the password if one was actually provided
        if (!empty($validated['password'])) {
            $validated['password'] = bcrypt($validated['password']);
        } else {
            unset($validated['password']);
        }

        $agent->update($validated);

        return response()->json(['data' => $agent]);
    }

    public function destroy(User $agent): JsonResponse
    {
        // Strict boundary: Prevent deleting agents from other agencies
        if ($agent->agency_id !== auth()->user()->agency_id) {
            return response()->json(['message' => 'Unauthorized operation.'], 403);
        }

        $agent->delete();

        // 204 No Content is the standard HTTP response for a successful deletion
        return response()->json(null, 204); 
    }
}