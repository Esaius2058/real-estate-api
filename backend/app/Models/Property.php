<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToAgency;
use App\Scopes\AgencyScope;
use App\Models\User;
use App\Models\PropertyImage;
use App\Models\Agency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory; 
use Illuminate\Database\Eloquent\SoftDeletes;

class Property extends Model
{
    use HasFactory, BelongsToAgency; 
    use SoftDeletes;

    protected $fillable = [
        'agency_id',
        'user_id',
        'title',
        'type',
        'price',
        'service_charge',
        'current_rent',
        'location',
        'city',
        'bedrooms',
        'baths',
        'sqft',
        'description',
        'amenities',
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

    protected $casts = [
        'amenities' => 'array',
    ];
    
    public function agent()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(PropertyImage::class);
    }

    public function agency()
    {
        return $this->belongsTo(Agency::class, 'agency_id');
    }
}