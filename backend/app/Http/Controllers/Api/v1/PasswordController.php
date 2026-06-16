<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use App\Models\User;

class PasswordController extends Controller
{
    /**
     * 1. FORGOT PASSWORD (PUBLIC)
     * Generates a random 6-digit PIN and stores it in cache.
     */
    public function sendResetCode(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)->first();

        // Always return a generic success message to prevent email enumeration attacks
        if (!$user) {
            return response()->json(['message' => 'If the email exists, a recovery code has been sent.']);
        }

        // Generate a cryptographically secure random 6-digit integer
        $code = (string) random_int(100000, 999999); 
        
        Cache::put('pwd_reset_' . $user->email, $code, now()->addMinutes(15));

        // TODO: Dispatch Email notification job here containing the $code
        // \Illuminate\Support\Facades\Mail::to($user->email)->send(new \App\Mail\PasswordResetMail($code));
        error_log("======= MAKAO DEBUG: RECOVERY CODE IS " . $code . " =======");
        
        return response()->json(['message' => 'If the email exists, a recovery code has been sent.']);
    }

    /**
     * 2. RESET PASSWORD (PUBLIC)
     * Verifies the 6-digit PIN and applies the new password.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
            'code' => 'required|string|size:6',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $cachedCode = Cache::get('pwd_reset_' . $request->email);

        if (!$cachedCode || $cachedCode !== $request->code) {
            return response()->json(['message' => 'Invalid or expired recovery code.'], 400);
        }

        $user = User::where('email', $request->email)->first();
        $user->password = Hash::make($request->password);
        $user->save();

        Cache::forget('pwd_reset_' . $request->email);
        
        // Revoke all existing sessions/tokens to force re-authentication
        $user->tokens()->delete();

        return response()->json(['message' => 'Password reset successful.']);
    }

    /**
     * 3. UPDATE PASSWORD (PROTECTED)
     * Authenticated user updating their password from account settings.
     */
    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|confirmed|different:current_password',
        ]);

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json(['message' => 'The provided current password is incorrect.'], 400);
        }

        $user->password = Hash::make($request->password);
        $user->save();

        return response()->json(['message' => 'Password updated successfully.']);
    }
}