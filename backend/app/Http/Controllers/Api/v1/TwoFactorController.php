<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class TwoFactorController extends Controller
{
    /**
     * Step 1: Generate the OTP and send it to the user.
     */
    public function requestEnable(Request $request): JsonResponse
    {
        $user = $request->user();

        // Generate a 6-digit code
        $code = sprintf("%06d", random_int(100000, 999999));
        
        $user->update([
            'two_factor_code' => $code,
            'two_factor_expires_at' => now()->addMinutes(10),
        ]);

        // For local testing: This logs the code to storage/logs/laravel.log
        Log::info("2FA Setup Code for {$user->email}: {$code}");
        
        // TODO: Replace with actual Mail facade when going live
        // \Illuminate\Support\Facades\Mail::to($user->email)->send(new \App\Mail\TwoFactorCodeMail($code));

        return response()->json([
            'message' => 'Verification code sent to your email.'
        ]);
    }

    /**
     * Step 2: Verify the OTP and lock the account into 2FA mode.
     */
    public function confirmEnable(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string|size:6',
        ]);

        $user = $request->user();

        if ($user->two_factor_code !== $request->code || 
            $user->two_factor_expires_at->isPast()) {
            return response()->json([
                'message' => 'Invalid or expired code. Please request a new one.'
            ], 400);
        }

        // Code is valid: Enable 2FA and wipe the temp code
        $user->update([
            'two_factor_enabled' => true,
            'two_factor_code' => null,
            'two_factor_expires_at' => null,
        ]);

        return response()->json([
            'message' => 'Two-factor authentication has been enabled.'
        ]);
    }

    /**
     * Step 3: Disable 2FA
     */
    public function disable(Request $request): JsonResponse
    {
        $request->user()->update([
            'two_factor_enabled' => false,
            'two_factor_code' => null,
            'two_factor_expires_at' => null,
        ]);

        return response()->json([
            'message' => 'Two-factor authentication disabled.'
        ]);
    }
}