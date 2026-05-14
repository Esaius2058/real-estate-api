<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertyImage extends Model
{
    protected $fillable = [
        'property_id', 'image_path', 'order'
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}