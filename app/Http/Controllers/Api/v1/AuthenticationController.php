<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request; // Added for registration fallback validation
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthenticationController extends Controller
{
    /**
     * Handle user registration.
     */
    public function register(Request $request): JsonResponse
    {
        // 1. Validate the incoming data profile
        $validated = $request->validate([
            'name'      => ['required', 'string', 'max:255'],
            'email'     => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password'  => ['required', 'string', 'min:8'], // Add 'confirmed' here if your form passes password_confirmation
            'role'      => ['nullable', 'string'],
            'agency_id' => ['nullable', 'string'], 
        ]);

        // 2. Persist the compliance user record
        $user = User::create([
            'name'      => $validated['name'],
            'email'     => $validated['email'],
            'password'  => Hash::make($validated['password']),
            'role'      => $validated['role'] ?? 'agent', // Fallback default assignment
            'agency_id' => $validated['agency_id'] ?? null,
        ]);

        // 3. Output structural state block matching login contract signatures
        return response()->json([
            'token' => $user->createToken('api-token')->plainTextToken,
            'user'  => [
                'id'        => $user->id,
                'name'      => $user->name,
                'role'      => $user->role,
                'agency_id' => $user->agency_id,
            ]
        ], 201); // Returns 201 Created status block
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
            'token' => $user->createToken('api-token')->plainTextToken,
            'user'  => [
                'id'        => $user->id,
                'name'      => $user->name,
                'role'      => $user->role,
                'agency_id' => $user->agency_id,
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