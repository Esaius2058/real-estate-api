<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EscrowDispute extends Model
{
    protected $fillable = ['escrow_id', 'raised_by_id', 'reason', 'status', 'resolution', 'admin_notes', 'resolved_by_id'];

    public function escrow(): BelongsTo
    {
        return $this->belongsTo(Escrow::class);
    }

    public function accuser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by_id');
    }
}