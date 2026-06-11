<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EscrowMilestone extends Model
{
    protected $fillable = [
        'escrow_id',
        'name',
        'amount',
        'status',
        'approved_at',
        'released_at'
    ];
    
    protected $casts = [
        'amount' => 'decimal:2',
        'approved_at' => 'datetime',
        'released_at' => 'datetime',
    ];
    
    public function escrow(): BelongsTo
    {
        return $this->belongsTo(Escrow::class);
    }
}