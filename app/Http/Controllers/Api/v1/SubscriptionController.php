<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\SubscriptionTier;
use App\Models\Subscription;
use App\Models\Payment;
use App\Services\DarajaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SubscriptionController extends Controller
{
    protected DarajaService $darajaService;

    public function __construct(DarajaService $darajaService)
    {
        $this->darajaService = $darajaService;
    }

    /**
     * GET /api/v1/subscriptions/tiers
     */
    public function getTiers()
    {
        $tiers = SubscriptionTier::where('is_active', true)
            ->orderBy('monthly_price')
            ->get();

        return response()->json($tiers);
    }

    /**
     * GET /api/v1/subscriptions/current
     */
    public function mySubscription(Request $request)
    {
        $user = $request->user();

        $subscription = Subscription::where('subscribable_type', Agency::class)
            ->where('subscribable_id', $user->agency_id)
            ->whereIn('status', ['active', 'pending'])
            ->latest()
            ->first();

        if (!$subscription) {
            return response()->json([
                'status' => 'inactive',
                'plan' => 'None',
                'tier_slug' => null,
                'max_properties' => 0,
                'features' => [],
                'starts_at' => null,
                'ends_at' => null,
            ]);
        }

        $tier = $subscription->tier;

        return response()->json([
            'id' => $subscription->id,
            'status' => $subscription->status,
            'plan' => $tier->name,
            'tier_slug' => $tier->slug,
            'max_properties' => $tier->max_properties,
            'features' => $tier->features,
            'starts_at' => $subscription->starts_at,
            'ends_at' => $subscription->ends_at,
        ]);
    }

    /**
     * POST /api/v1/subscriptions/subscribe-mpesa
     */
    public function subscribeMpesa(Request $request)
    {
        $request->validate([
            'tier_slug' => 'required|exists:subscription_tiers,slug',
            'billing_cycle' => 'required|in:monthly,yearly',
            'phone' => 'required|string',
        ]);

        $user = $request->user();

        if (!$user->agency_id) {
            return response()->json([
                'success' => false,
                'message' => 'You must belong to an agency before subscribing.',
            ], 422);
        }

        $tier = SubscriptionTier::where('slug', $request->tier_slug)->firstOrFail();

        $amount = $request->billing_cycle === 'monthly'
            ? (int) round($tier->monthly_price)
            : (int) round($tier->yearly_price ?? $tier->monthly_price * 12);

        // TEMPORARY — lets you tap through feature-gated screens for KES 1
        // instead of the real tier price while testing. Never fires in production.
        if (!app()->environment('production')) {
            $amount = 1;
        }

        $reference = 'SUB-' . strtoupper(Str::random(10));

        try {
            $subscription = Subscription::create([
                'subscribable_type' => Agency::class,
                'subscribable_id' => $user->agency_id,
                'tier_id' => $tier->id,
                'billing_cycle' => $request->billing_cycle,
                'status' => 'pending',
                'payment_provider' => 'mpesa',
            ]);

            $result = $this->darajaService->stkPush($request->phone, $amount, $reference);

            Payment::create([
                'user_id' => $user->id,
                'subscription_id' => $subscription->id,
                'amount' => $amount,
                'checkout_request_id' => $result['CheckoutRequestID'] ?? null,
                'transaction_reference' => $reference,
                'status' => 'pending',
                'payment_type' => 'subscription',
                'payment_method' => 'mpesa',
                'currency' => 'KES',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'STK push sent. Enter your M-Pesa PIN to complete payment.',
                'checkout_request_id' => $result['CheckoutRequestID'] ?? null,
                'reference' => $reference,
            ]);
        } catch (\Exception $e) {
            Log::error('Subscribe (M-Pesa) exception', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Could not initiate M-Pesa payment: ' . $e->getMessage(),
                'checkout_request_id' => null,
                'reference' => null,
            ], 422);
        }
    }
}