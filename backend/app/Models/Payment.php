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
        'user_id',
        'property_id',
        'escrow_id',
        'subscription_id',
        'amount',
        'merchant_request_id',
        'checkout_request_id',
        'receipt_number',
        'status',
        'payment_type',
        'payment_method',
        'transaction_reference',
        'paid_at',
        'currency',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
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

    public function escrow(): BelongsTo
    {
        return $this->belongsTo(Escrow::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

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

    public function markAsCompleted(?string $receiptNumber = null): void
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