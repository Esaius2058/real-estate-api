<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\User;
use App\Http\Requests\Agency\UpdateAgencyRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AgencyController extends Controller
{
    /**
     * GET /v1/agency
     * Returns the authenticated user's agency.
     */
    public function show(): JsonResponse
    {
        $agency = Agency::find(auth()->user()->agency_id);

        if (!$agency) {
            return response()->json(['message' => 'Agency not found.'], 404);
        }

        return response()->json(['data' => $agency]);
    }

    /**
     * POST /v1/vault/initialize-workspace
     * Creates a new agency, generates a join code, and assigns the user as Admin.
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

        return response()->json([
            'profile' => [
                'id'       => $user->id,
                'name'     => $user->name,
                'email'    => $user->email,
                'role'     => ucfirst($user->role),
                'agencyId' => $agency->id,
            ],
            'user' => [
                'id'        => $user->id,
                'name'      => $user->name,
                'role'      => $user->role,
                'agency_id' => $agency->id,
            ],
            'agency' => $agency,
        ], 201);
    }

    /**
     * POST /v1/agency/join
     * Assigns an existing user to an agency via join code.
     */
    public function join(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'join_code' => ['required', 'string', 'exists:agencies,join_code'],
        ]);

        $user = auth()->user();

        if ($user->agency_id) {
            return response()->json(['message' => 'You are already assigned to an agency.'], 409);
        }

        $agency = Agency::where('join_code', strtoupper($validated['join_code']))->firstOrFail();

        $user->update([
            'agency_id' => $agency->id,
            'role'      => 'agent',
        ]);

        return response()->json([
            'profile' => [
                'id'       => $user->id,
                'name'     => $user->name,
                'email'    => $user->email,
                'role'     => 'Agent',
                'agencyId' => $agency->id,
            ],
            'user' => [
                'id'        => $user->id,
                'name'      => $user->name,
                'role'      => $user->role,
                'agency_id' => $agency->id,
            ],
        ]);
    }

    /**
     * PUT /v1/agency/{agency}
     * Updates agency details. Admin only.
     */
    public function update(UpdateAgencyRequest $request, Agency $agency): JsonResponse
    {
        $this->authorize('update', $agency);

        $agency->update($request->validated());

        return response()->json(['data' => $agency->fresh()]);
    }
}