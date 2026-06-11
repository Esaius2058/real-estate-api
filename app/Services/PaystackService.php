<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaystackService
{
    protected $secretKey;
    protected $baseUrl;

    public function __construct()
    {
        $this->secretKey = config('services.paystack.secret_key');
        $this->baseUrl = config('paystack.payment_url');
    }

    /**
     * Initialize a transaction.
     */
    public function initializeTransaction($email, $amount, $metadata = [], $callbackUrl = null)
    {
        $response = Http::withToken($this->secretKey)
            ->post($this->baseUrl . '/transaction/initialize', [
                'email' => $email,
                'amount' => $amount * 100, // minor units
                'metadata' => $metadata,
                'callback_url' => $callbackUrl ?? config('paystack.callback_url'),
            ]);

        if ($response->successful()) {
            return $response->json();
        }

        Log::error('Paystack init failed', ['response' => $response->body()]);
        return null;
    }

    /**
     * Verify a transaction.
     */
    public function verifyTransaction($reference)
    {
        $response = Http::withToken($this->secretKey)
            ->get($this->baseUrl . '/transaction/verify/' . $reference);

        if ($response->successful()) {
            return $response->json();
        }

        Log::error('Paystack verify failed', ['reference' => $reference, 'response' => $response->body()]);
        return null;
    }

    /**
     * RESTORED METHOD FOR DISBURSAL PAYOUTS
     * Transfer funds from your corporate Paystack balance directly to a recipient (e.g., M-Pesa/Bank profile)
     * * @param float|int $amount Standard currency amount (e.g. KES 500)
     * @param string $recipientCode Paystack recipient token (e.g., RCP_xxxxxxxx)
     * @param string $reason Brief transactional tracking narration
     * @return array|null
     */
    public function initiateTransfer($amount, $recipientCode, $reason = 'Escrow Disbursal')
    {
        $response = Http::withToken($this->secretKey)
            ->post($this->baseUrl . '/transfer', [
                'source' => 'balance',
                'amount' => $amount * 100, // Automatically scaled to minor units (cents/kobo)
                'recipient' => $recipientCode,
                'reason' => $reason,
            ]);

        if ($response->successful()) {
            return $response->json();
        }

        Log::error('Paystack transfer failed', ['response' => $response->body()]);
        return null;
    }

    /**
     * Create a subscription plan.
     */
    public function createPlan($name, $amount, $interval = 'monthly')
    {
        $response = Http::withToken($this->secretKey)
            ->post($this->baseUrl . '/plan', [
                'name' => $name,
                'amount' => $amount * 100,
                'interval' => $interval,
                'currency' => 'KES',
            ]);

        if ($response->successful()) {
            return $response->json();
        }

        Log::error('Paystack create plan failed', ['response' => $response->body()]);
        return null;
    }

    /**
     * Create a subscription for a customer.
     */
    public function createSubscription($customerEmail, $planCode, $authorizationCode = null)
    {
        $payload = [
            'customer' => $customerEmail,
            'plan' => $planCode,
            'start_date' => now()->toDateTimeString(),
        ];
        if ($authorizationCode) {
            $payload['authorization'] = $authorizationCode;
        }

        $response = Http::withToken($this->secretKey)
            ->post($this->baseUrl . '/subscription', $payload);

        if ($response->successful()) {
            return $response->json();
        }

        Log::error('Paystack subscription create failed', ['response' => $response->body()]);
        return null;
    }

    /**
     * List available plans.
     */
    public function listPlans()
    {
        $response = Http::withToken($this->secretKey)
            ->get($this->baseUrl . '/plan');

        if ($response->successful()) {
            return $response->json()['data'] ?? [];
        }
        return [];
    }
}