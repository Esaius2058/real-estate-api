<?php

declare(strict_types=1);

namespace App\Services\Signature;

use Illuminate\Http\Request;

class WebhookSignatureService
{
    /**
     * Verify the cryptographic signature from the payment gateway.
     */
    public function verify(Request $request): bool
    {
        // Adjust the header key to match your specific gateway (e.g., Stripe, Paystack, Flutterwave)
        $signature = $request->header('X-Webhook-Signature'); 
        $payload = $request->getContent();
        $secret = config('escrow.webhook_secret');

        if (!$signature || !$secret) {
            return false;
        }

        $computedSignature = hash_hmac('sha256', $payload, $secret);

        return hash_equals($signature, $computedSignature);
    }
}