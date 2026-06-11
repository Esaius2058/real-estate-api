<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    protected $fillable = [
        'user_id', 
        'subscription_tier_id', 
        'status', 
        'gateway_reference', 
        'ends_at'
    ];

    protected $casts = [
        'ends_at' => 'datetime'
    ];

    /**
     * Get the tier plan details associated with this user subscription.
     */
    public function tier(): BelongsTo
    {
        return $this->belongsTo(SubscriptionTier::class, 'subscription_tier_id');
    }
}