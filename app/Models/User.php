<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToAgency;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable, BelongsToAgency;

    protected $fillable = [
        'agency_id', 'name', 'email', 'password', 'role',
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    public function assignedLeads(): HasMany
    {
        return $this->hasMany(Lead::class, 'agent_id');
    }
}