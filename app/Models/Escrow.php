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
        'total_paid',
        'remaining',
        'is_fully_funded',
        'terms',
        'status',
        'funded_at',
        'completed_at',
        'created_by',
    ];

    protected $casts = [
        'amount'          => 'decimal:2',
        'total_paid'      => 'decimal:2',
        'remaining'       => 'decimal:2',
        'is_fully_funded' => 'boolean',
        'funded_at'       => 'datetime',
        'completed_at'    => 'datetime',
    ];

    // ─── Relationships ────────────────────────────────────────────
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

    public function milestones(): HasMany
    {
        return $this->hasMany(EscrowMilestone::class);
    }

    public function disputes(): HasMany
    {
        return $this->hasMany(EscrowDispute::class);
    }

    // ─── Computed Attributes ──────────────────────────────────────
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
        return max(0, $this->amount - $this->getTotalPaidAttribute());
    }

    public function getIsFullyFundedAttribute(): bool
    {
        return $this->getTotalPaidAttribute() >= $this->amount;
    }

    // ─── Business Logic ───────────────────────────────────────────
    public function isFullyFunded(): bool
    {
        return $this->getTotalPaidAttribute() >= $this->amount;
    }

    public function markAsFunded(): void
    {
        if ($this->isFullyFunded() && $this->status === 'pending_funding') {
            $this->update([
                'status'    => 'funded',
                'funded_at' => now(),
            ]);
        }
    }

    /**
     * Called by PaymentController::updateEscrowProgress() after any escrow
     * payment (M-Pesa or Paystack) is marked completed.
     */
    public function applyPayment(): void
    {
        $this->refresh();
        $this->markAsFunded();
    }
}