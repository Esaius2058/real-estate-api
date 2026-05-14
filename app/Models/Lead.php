<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Lead extends Model
{
    use BelongsToAgency;

    protected $fillable = [
        'agency_id', 'agent_id', 'name', 'email', 'phone', 
        'kanban_stage', 'min_budget', 'max_budget', 
        'min_bedrooms', 'preferred_city'
    ];

    public function assignedAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(SecureDocument::class);
    }

    public function transaction(): HasOne
    {
        return $this->hasOne(Transaction::class);
    }
}