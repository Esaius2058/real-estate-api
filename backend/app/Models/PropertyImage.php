<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

# TODO: RUN 'composer require league/flysystem-aws-s3-v3' THEN RUN 'php artisan config:clear'
class PropertyImage extends Model
{
    protected $fillable = [
        'property_id', 's3_path', 'is_primary'
    ];

    // Force Laravel to append this computed property to the JSON serialization
    protected $appends = ['signed_url'];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * Dynamically generate a Supabase signed URL valid for 60 minutes.
     */
    public function getSignedUrlAttribute(): ?string
    {
        if (empty($this->s3_path)) {
            return null;
        }

        // Pass through if the path is already a fully resolved HTTP link
        if (str_starts_with($this->s3_path, 'http')) {
            return $this->s3_path;
        }

        try {
            return Storage::disk('s3')->temporaryUrl(
                $this->s3_path, 
                now()->addMinutes(60)
            );
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to sign Supabase image: ' . $e->getMessage());
            return null;
        }
    }
}