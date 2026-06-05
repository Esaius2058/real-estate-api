<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertyImage extends Model
{
    protected $fillable = [
        'property_id', 's3_path', 'is_primary'
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}