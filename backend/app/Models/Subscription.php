<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Subscription extends Model
{
    protected $fillable = [
        'subscribable_type',
        'subscribable_id',
        'tier_id',
        'billing_cycle',
        'status',
        'starts_at',
        'ends_at',
        'canceled_at',
        'payment_provider',
        'provider_subscription_id',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'canceled_at' => 'datetime',
    ];

    public function tier(): BelongsTo
    {
        return $this->belongsTo(SubscriptionTier::class, 'tier_id');
    }

    public function subscribable(): MorphTo
    {
        return $this->morphTo();
    }

    public static function activateFromPaymentId(?int $subscriptionId): void
    {
        if (!$subscriptionId) {
            return;
        }

        $subscription = static::find($subscriptionId);

        if (!$subscription || $subscription->status === 'active') {
            return;
        }

        $startsAt = $subscription->starts_at ?? now();

        $subscription->update([
            'status' => 'active',
            'starts_at' => $startsAt,
            'ends_at' => $subscription->billing_cycle === 'yearly'
                ? $startsAt->copy()->addYear()
                : $startsAt->copy()->addMonth(),
        ]);
    }
}