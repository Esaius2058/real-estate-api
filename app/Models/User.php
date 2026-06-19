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
        'agency_id', 'name', 'email', 'password', 'role', 'avatar_path', 'status', 'last_active_at' , 'access'
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected $casts = [
    'email_verified_at' => 'datetime',
    'last_active_at' => 'datetime',
    'created_at' => 'datetime',
    ];

    public function assignedLeads(): HasMany
    {
        return $this->hasMany(Lead::class, 'agent_id');
    }

    
    // ✅ ADD THESE RELATIONSHIPS
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
    
    // ✅ ADD HELPER METHOD FOR ROLE CHECK
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

    public function subscriptions()
    {
        return $this->morphMany(Subscription::class, 'subscribable');
    }

    public function activeSubscription()
    {
        return $this->morphOne(Subscription::class, 'subscribable')
                    ->where('status', 'active')
                    ->where('ends_at', '>', now())
                    ->latest();
    }
}

