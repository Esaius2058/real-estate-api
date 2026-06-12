<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionTier; 
use App\Models\Subscription;     
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SubscriptionController extends Controller
{
    /**
     * Get the authenticated user's current active subscription details.
     */
    public function currentSubscription(Request $request)
    {
        $subscription = Subscription::with('tier')
            ->where('user_id', $request->user()->id)
            ->latest()
            ->first();

        if (!$subscription) {
            return response()->json([
                'status' => 'inactive',
                'plan' => 'Free / Sandbox Trial',
                'max_properties' => 1,
                'features' => ['basic_listings']
            ]);
        }

        return response()->json([
            'status' => $subscription->status,
            'plan' => $subscription->tier->name,
            'max_properties' => $subscription->tier->max_properties,
            'features' => $subscription->tier->features,
            'ends_at' => $subscription->ends_at
        ]);
    }

    /**
     * Initialize a Paystack billing reference checkout session.
     */
    public function initializeCheckout(Request $request)
    {
        $request->validate([
            'tier_slug' => 'required|exists:subscription_tiers,slug',
            'gateway' => 'required|in:paystack,card'
        ]);

        $tier = SubscriptionTier::where('slug', $request->tier_slug)->firstOrFail();
        $user = $request->user();

        // Generate a clean, unique transaction tracking code
        $reference = 'SUB_' . Str::random(12);

        // Pre-stage the subscription track record as pending payment
        Subscription::create([
            'user_id' => $user->id,
            'subscription_tier_id' => $tier->id,
            'status' => 'pending_payment',
            'gateway_reference' => $reference,
            'ends_at' => now()->addMonth()
        ]);

        return response()->json([
            'message' => 'Checkout initialization verification pool open.',
            'reference' => $reference,
            'amount' => $tier->price_monthly,
            'email' => $user->email
        ]);
    }
}