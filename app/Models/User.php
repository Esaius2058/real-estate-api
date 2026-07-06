<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToAgency;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Subscription;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable, BelongsToAgency;

    protected $fillable = [
        'agency_id', 'name', 'email', 'password', 'role', 'avatar_path',
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    public function assignedLeads(): HasMany
    {
        return $this->hasMany(Lead::class, 'agent_id');
    }

    public function buyerEscrows(): HasMany
    {
        return $this->hasMany(Escrow::class, 'buyer_id');
    }

    public function sellerEscrows(): HasMany
    {
        return $this->hasMany(Escrow::class, 'seller_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function properties(): HasMany
    {
        return $this->hasMany(Property::class);
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function hasRole(string $role): bool
    {
        return $this->role === $role;
    }

    public function isAdmin(): bool
    {
        return in_array($this->role, ['admin', 'Admin']);
    }

    public function isAgent(): bool
    {
        return $this->role === 'agent';
    }

    public function isBroker(): bool
    {
        return $this->role === 'broker';
    }

    /**
     * Subscriptions belong to the Agency, not the individual User.
     * These delegate through so existing call sites like
     * $user->activeSubscription() keep working without changes elsewhere.
     * NOTE: unlike the old version, this now returns a Subscription|null
     * directly (not a relation builder) since it's just proxying the
     * agency's own relation.
     */
    public function activeSubscription(): ?Subscription
    {
        return $this->agency?->activeSubscription()->first();
    }

    public function currentTier(): ?SubscriptionTier
    {
        return $this->agency?->currentTier();
    }

    public function hasFeature(string $key): bool
    {
        return $this->agency?->hasFeature($key) ?? false;
    }

    public function propertyLimit(): int
    {
        return $this->agency?->propertyLimit() ?? 3;
    }
}