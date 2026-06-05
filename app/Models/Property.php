<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToAgency;
use App\Scopes\AgencyScope;
use App\Models\User;
use App\Models\PropertyImage;
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

    protected static function booted(): void
    {
        static::addGlobalScope(new AgencyScope);

        static::creating(function (Property $property) {
            if (auth()->check() && is_null($property->agency_id)) {
                $property->agency_id = auth()->user()->agency_id;
            }
        });
    }

    public function agent()
    {
        return $this->belongsTo(User::class, 'user_id');
    }


    public function images(): HasMany
    {
        return $this->hasMany(PropertyImage::class);
    }
}