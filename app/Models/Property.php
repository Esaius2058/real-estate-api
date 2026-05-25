<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Property extends Model
{
    use BelongsToAgency;

    protected $fillable = [
        'agency_id',
        'user_id',
        'title',
        'price',
        'location',
        'city',
        'bedrooms',
        'baths',
        'sqft',
        'description',
        'status',
        'contract_end_date'
    ];

    public function images(): HasMany
    {
        return $this->hasMany(PropertyImage::class);
    }
}