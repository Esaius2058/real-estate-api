<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecureDocument extends Model
{
    use BelongsToAgency;

    protected $fillable = [
        'agency_id', 'lead_id', 'property_id', 'document_type', 'file_path'
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}