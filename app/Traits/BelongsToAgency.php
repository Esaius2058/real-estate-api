<?php

declare(strict_types=1);

namespace App\Traits;

use App\Models\Agency;
use App\Scopes\AgencyScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToAgency
{
    /**
     * Boot the trait to apply the global scope and creating event.
     */
    protected static function bootBelongsToAgency(): void
    {
        // 1. Automatically filter reads
        static::addGlobalScope(new AgencyScope());

        // 2. Automatically append agency_id on writes
        static::creating(function ($model) {
            if (auth()->hasUser() && auth()->user()->agency_id && empty($model->agency_id)) {
                $model->agency_id = auth()->user()->agency_id;
            }
        });
    }

    /**
     * Relationship mapping.
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }
}