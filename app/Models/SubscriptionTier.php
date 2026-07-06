<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionTier extends Model
{
    protected $fillable = [
        'name', 
        'slug', 
        'max_properties', 
        'features',
        'monthly_price',
        'yearly_price',
        'is_active',
    ];

    protected $casts = [
        'features' => 'array'
    ];
}