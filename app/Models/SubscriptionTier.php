<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionTier extends Model
{
    protected $fillable = [
        'name', 
        'slug', 
        'price_monthly', 
        'max_properties', 
        'features'
    ];

    protected $casts = [
        'features' => 'array'
    ];
}