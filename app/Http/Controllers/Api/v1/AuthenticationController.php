<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\Agency;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthenticationController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'email'       => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password'    => ['required', 'string', 'min:8'],
            'agency_code' => ['required', 'string', 'exists:agencies,join_code'],
        ]);

        // Resolve the agency from the join code
        $agency = Agency::where('join_code', strtoupper($validated['agency_code']))->firstOrFail();

        $user = User::create([
            'name'      => $validated['name'],
            'email'     => $validated['email'],
            'password'  => Hash::make($validated['password']),
            'role'      => 'agent', // All self-registered users are agents
            'agency_id' => $agency->id,
        ]);

        return response()->json([
            'message' => 'User registered successfully',
            'token'   => $user->createToken('api-token')->plainTextToken,
            'user'    => [
                'id'        => $user->id,
                'name'      => $user->name,
                'role'      => $user->role,
                'agency_id' => $user->agency_id,
            ],
            'profile' => null,
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->email)->first();

        // Standardized to send an explicit 401 response instead of a structural 422 framework exception
        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Invalid email or password credentials.'
            ], 401);
        }

        return response()->json([
            'message' => 'Login successful',
            'token'   => $user->createToken('api-token')->plainTextToken,
            'user'    => [
                'id'        => $user->id,
                'name'      => $user->name,
                'role'      => $user->role,
                'agency_id' => $user->agency_id,
            ],
            'profile' => [
                'id'       => $user->id,
                'email'    => $user->email,
                'role'     => ucfirst($user->role),
                'agencyId' => $user->agency_id,
                'name'     => $user->name,
            ],
        ]);
    }

    /**
     * Fetch authenticatable session data on state restoration.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user'    => [
                'id'        => $user->id,
                'name'      => $user->name,
                'role'      => $user->role,
                'agency_id' => $user->agency_id,
            ],
            'profile' => [
                'id'       => $user->id,
                'email'    => $user->email,
                'role'     => ucfirst($user->role),
                'agencyId' => $user->agency_id,
                'name'     => $user->name,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user) {
            $user->tokens()->delete(); // Clear out current active personal access tokens safely
        }

        return response()->json(['message' => 'Logged out successfully']);
    }
}