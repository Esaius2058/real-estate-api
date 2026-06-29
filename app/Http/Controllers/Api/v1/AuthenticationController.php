<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\Agency;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Http\Controllers\Api\v1\LogController;

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

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Invalid email or password credentials.'
            ], 401);
        }

        // Log the login activity
        \App\Http\Controllers\Api\v1\LogController::logActivity(
            $user, 
            'USER_LOGIN', 
            "User {$user->name} logged in from " . $request->ip()
        );

        // Wipe old tokens
        $user->tokens()->delete();

        $expiration = $request->boolean('remember') ? now()->addDays(7) : now()->addHours(2);
        $token = $user->createToken('makao-auth-token', ['*'], $expiration)->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'token'   => $token,
            'user'    => [
                'id'        => $user->id,
                'name'      => $user->name,
                'role'      => $user->role,
                'agency_id' => $user->agency_id,
            ],
            // RESTORED: The profile array your frontend desperately needs!
            'profile' => [
                'id'       => $user->id,
                'email'    => $user->email,
                'role'     => ucfirst($user->role),
                'agencyId' => $user->agency_id,
                'name'     => $user->name,
            ],
        ]);
    }
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        // 1. Validate data fields
        $validated = $request->validate([
            'name'   => ['required', 'string', 'max:255'],
            'email'  => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'avatar' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif', 'max:2048'], // Max 2MB
        ]);

        // 2. Process Binary Avatar File Stream if attached
        if ($request->hasFile('avatar')) {
            // Delete old avatar if it exists to clean disk space
            if ($user->avatar_path) {
                Storage::disk('public')->delete($user->avatar_path);
            }

            // Save file to storage/app/public/avatars
            $path = $request->file('avatar')->store('avatars', 'public');
            $user->avatar_path = $path;
        }

        // 3. Persist standard attributes
        $user->name = $validated['name'];
        $user->email = $validated['email'];
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Profile configurations synchronized successfully.',
            'user'    => $user,
            'avatar_url' => $user->avatar_path ? asset('storage/' . $user->avatar_path) : null
        ], 200);
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
                'avatar_path' => $user->avatar_path,
            ],
            'profile' => [
                'id'        => $user->id,
                'email'     => $user->email,
                'role'      => ucfirst($user->role),
                'agencyId'  => $user->agency_id,
                'name'      => $user->name,
                // Pass down the nested agency object so React can read profile.agency.name
                'agency'    => $user->agency ? [
                    'id'   => $user->agency->id,
                    'name' => $user->agency->name,
                ] : null,
            ],
        ]);
    }

public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user) {
            // Log the logout action before deleting the tokens
            \App\Http\Controllers\Api\v1\LogController::logActivity(
                $user, 
                'USER_LOGOUT', 
                "User {$user->name} logged out."
            );

            // Clear out current active personal access tokens safely
            $user->tokens()->delete(); 
        }

        return response()->json(['message' => 'Logged out successfully']);
    }
}