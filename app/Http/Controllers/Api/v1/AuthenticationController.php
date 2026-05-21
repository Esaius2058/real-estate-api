<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthenticationController extends Controller
{
    /**
     * Handle user registration.
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'      => ['required', 'string', 'max:255'],
            'email'     => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password'  => ['required', 'string', 'min:8'],
            'role'      => ['nullable', 'string'],
            'agency_id' => ['nullable', 'numeric'], 
        ]);

        $user = User::create([
            'name'      => $validated['name'],
            'email'     => $validated['email'],
            'password'  => Hash::make($validated['password']),
            'role'      => $validated['role'] ?? 'agent',
            'agency_id' => $validated['agency_id'] ?? null,
        ]);

        return response()->json([
            'message' => 'User registered successfully',
            'token' => $user->createToken('api-token')->plainTextToken,
            'user'  => [
                'id'        => $user->id,
                'name'      => $user->name,
                'role'      => $user->role,
                'agency_id' => $user->agency_id,
            ],
            // Kept as null for registration based on your frontend's workspace initialization flow
            'profile' => null 
        ], 201); 
    }

    /**
     * Handle user authentication session generation.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        return response()->json([
            'message' => 'Login successful',
            'token' => $user->createToken('api-token')->plainTextToken,
            'user'  => [
                'id'        => $user->id,
                'name'      => $user->name,
                'role'      => $user->role,
                'agency_id' => $user->agency_id,
            ],
            'profile' => [
                'id'       => $user->id,
                'email'    => $user->email,
                'role'     => ucfirst($user->role), // Transforms 'agent' to 'Agent'
                'agencyId' => $user->agency_id,
                'name'     => $user->name,
            ]
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user'  => [
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
            ]
        ]);
    }

    /**
     * Destroy active session authentication states.
     */
    public function logout(): JsonResponse
    {
        auth()->user()->tokens()->delete();

        return response()->json(['message' => 'Logged out successfully']);
    }
}