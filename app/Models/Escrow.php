<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Escrow extends Model
{
    protected $fillable = [
        'property_id',
        'buyer_id',
        'seller_id',
        'agency_id',
        'amount',
        'terms',
        'status',
        'funded_at',
        'completed_at',
        'created_by'
    ];
    
    protected $casts = [
        'amount' => 'decimal:2',
        'funded_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
    
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
    
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }
    
    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }
    
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }
    
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
    
    public function getProgressAttribute(): float
    {
        $totalPaid = $this->payments()->where('status', 'completed')->sum('amount');
        if ($this->amount <= 0) return 0;
        return round(($totalPaid / $this->amount) * 100, 2);
    }
    
    public function getTotalPaidAttribute(): float
    {
        return $this->payments()->where('status', 'completed')->sum('amount');
    }
    
    public function getRemainingAttribute(): float
    {
        return max(0, $this->amount - $this->total_paid);
    }
    
    public function isFullyFunded(): bool
    {
        return $this->total_paid >= $this->amount;
    }
    
    public function markAsFunded(): void
    {
        if ($this->isFullyFunded() && $this->status === 'pending_funding') {
            $this->update([
                'status' => 'funded',
                'funded_at' => now(),
            ]);
        }
    }
}