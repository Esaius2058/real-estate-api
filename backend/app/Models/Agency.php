<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Agency extends Model
{
    use HasFactory;
    
    protected $fillable = [
        'name',
        'join_code', 
        'location',
        'subscription_tier',
    ];
    
    protected $casts = [
        'config' => 'array',
    ];

    public function agents(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function properties(): HasMany
    {
        return $this->hasMany(Property::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function subscriptions(): MorphMany
    {
        return $this->morphMany(Subscription::class, 'subscribable');
    }

    public function activeSubscription(): MorphOne
    {
        return $this->morphOne(Subscription::class, 'subscribable')
                    ->where('status', 'active')
                    ->where('ends_at', '>', now())
                    ->latest();
    }

    public function currentTier(): ?SubscriptionTier
    {
        return $this->activeSubscription()->first()?->tier;
    }

    public function hasFeature(string $key): bool
    {
        $tier = $this->currentTier();
        return $tier ? in_array($key, $tier->feature_keys ?? [], true) : false;
    }

    public function propertyLimit(): int
    {
        return $this->currentTier()?->max_properties ?? 3;
    }
}