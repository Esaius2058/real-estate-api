<?php

return [
    'public_key' => env('PAYSTACK_PUBLIC_KEY'),
    'secret_key' => env('PAYSTACK_SECRET_KEY'),
    'payment_url' => env('PAYSTACK_PAYMENT_URL', 'https://api.paystack.co'),
    //'callback_url' => env('PAYSTACK_CALLBACK_URL', url('/api/v1/paystack/callback')),
    'callback_url' => env('PAYSTACK_CALLBACK_URL', '/api/v1/paystack/callback'),
];