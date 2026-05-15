<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class DarajaService
{
    protected string $baseUrl;
    protected string $shortcode;
    protected string $passkey;

    public function __construct()
    {
        // Switch URLs automatically based on your .env
        $this->baseUrl = config('services.mpesa.env') === 'production' 
            ? 'https://api.safaricom.co.ke' 
            : 'https://sandbox.safaricom.co.ke';

        $this->shortcode = config('services.mpesa.shortcode');
        $this->passkey = config('services.mpesa.passkey');
    }

    /**
     * Get and Cache the Access Token to prevent rate limiting.
     */
    public function authenticate(): string
    {
        // Tokens live for 1 hour. We cache for 50 minutes to be safe.
        return Cache::remember('mpesa_access_token', now()->addMinutes(50), function () {
            
            $key = config('services.mpesa.consumer_key');
            $secret = config('services.mpesa.consumer_secret');

            $response = Http::withBasicAuth($key, $secret)
                ->get("{$this->baseUrl}/oauth/v1/generate?grant_type=client_credentials");

            if ($response->failed()) {
                Log::error('Daraja Authentication Failed', ['response' => $response->json()]);
                throw new \Exception('Could not authenticate with Safaricom.');
            }

            return $response->json('access_token');
        });
    }

    /**
     * Trigger the M-Pesa Express (STK Push) prompt on the user's phone.
     */
    public function stkPush(string $phoneNumber, int $amount, string $accountReference): array
    {
        $token = $this->authenticate();
        $timestamp = now()->format('YmdHis');
        $password = base64_encode($this->shortcode . $this->passkey . $timestamp);
        
        // Clean the phone number to Safaricom's strict format
        $formattedPhone = $this->formatPhoneNumber($phoneNumber);

        // Your ngrok URL goes here for testing. Update this when deploying to production.
        // Example: https://a1b2c3d4.ngrok.app/api/payments/callback
        $callbackUrl = env('APP_URL') . '/api/payments/callback'; 

        $payload = [
            'BusinessShortCode' => $this->shortcode,
            'Password' => $password,
            'Timestamp' => $timestamp,
            'TransactionType' => 'CustomerPayBillOnline',
            'Amount' => $amount,
            'PartyA' => $formattedPhone,
            'PartyB' => $this->shortcode,
            'PhoneNumber' => $formattedPhone,
            'CallBackURL' => $callbackUrl,
            'AccountReference' => $accountReference,
            'TransactionDesc' => 'Property Payment',
        ];

        Log::info('Initiating STK Push', ['payload' => $payload]);

        $response = Http::withToken($token)
            ->post("{$this->baseUrl}/mpesa/stkpush/v1/processrequest", $payload);

        if ($response->failed()) {
            Log::error('STK Push Request Failed', ['response' => $response->json()]);
            throw new \Exception('Safaricom rejected the STK Push request.');
        }

        return $response->json();
    }

    /**
     * Safaricom strictly requires the 2547XXXXXXXX format.
     * This converts 07..., 01..., or +254... into the correct format.
     */
    private function formatPhoneNumber(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);

        if (str_starts_with($phone, '0')) {
            $phone = preg_replace('/^0/', '254', $phone);
        }

        if (strlen($phone) !== 12) {
            throw new \InvalidArgumentException('Invalid phone number length.');
        }

        return $phone;
    }
}