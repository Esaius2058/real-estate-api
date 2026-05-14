<?php

namespace App\Http\Controllers\Api\V1\Agency;

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
}