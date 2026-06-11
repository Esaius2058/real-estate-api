<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'agency_id',
        'tenant_id',
        'user_id',
        'property_id',
        'escrow_id',           // ✅ ADD THIS
        'amount',
        'merchant_request_id',
        'checkout_request_id',
        'receipt_number',
        'status',
        'payment_type',        // ✅ ADD THIS (direct, escrow, subscription)
        'paid_at',             // ✅ ADD THIS
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',  // ✅ ADD THIS
    ];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    // ✅ ADD THIS RELATIONSHIP
    public function escrow(): BelongsTo
    {
        return $this->belongsTo(Escrow::class);
    }

    // ✅ ADD HELPER METHODS
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function markAsCompleted(string $receiptNumber = null): void
    {
        $this->update([
            'status' => 'completed',
            'receipt_number' => $receiptNumber ?? $this->receipt_number,
            'paid_at' => now(),
        ]);
    }

    public function markAsFailed(): void
    {
        $this->update([
            'status' => 'failed',
        ]);
    }

    public function markAsCancelled(): void
    {
        $this->update([
            'status' => 'cancelled',
        ]);
    }
}