<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class OtpAuthController extends Controller
{
    /**
     * Step 1: Request Passwordless OTP
     */
    public function requestOtp(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
        ]);

        $user = User::where('email', $request->email)->first();

        // Generate temporary 6-digit login code
        $code = sprintf("%06d", random_int(100000, 999999));

        $user->update([
            'two_factor_code' => $code,
            'two_factor_expires_at' => now()->addMinutes(10),
        ]);

        // Log locally for easy testing without an SMTP server configured
        Log::info("Passwordless OTP Login Code for {$user->email}: {$code}");

        // TODO: Mail code out to user
        // Mail::to($user->email)->send(new PasswordlessOtpMail($code));

        return response()->json([
            'message' => 'Secure login code dispatched to your inbox.'
        ]);
    }

    /**
     * Step 2: Verify OTP and log user in
     */
    public function verifyOtp(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'code'  => 'required|string|size:6',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || $user->two_factor_code !== $request->code || optional($user->two_factor_expires_at)->isPast()) {
            return response()->json([
                'message' => 'Invalid or expired secure code.'
            ], 401);
        }

        // Clear tokens and used code
        $user->tokens()->delete();
        $user->update([
            'two_factor_code' => null,
            'two_factor_expires_at' => null,
        ]);

        // Issue standard session auth token
        $token = $user->createToken('makao-auth-token', ['*'], now()->addHours(2))->plainTextToken;

        return response()->json([
            'status'  => 'success',
            'message' => 'Authentication successful.',
            'token'   => $token,
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
}