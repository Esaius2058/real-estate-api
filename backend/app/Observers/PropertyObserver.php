<?php

namespace App\Observers;

use App\Models\Property;
use App\Jobs\DispatchPropertyMatch;

class PropertyObserver
{
    /**
     * Handle the Property "created" event.
     */
    public function created(Property $property): void
    {
        if ($property->status === 'active' || $property->status === 'active_listing') {
            DispatchPropertyMatch::dispatch($property);
        }
    }

    /**
     * Handle the Property "updated" event.
     */
    public function updated(Property $property): void
    {
        // Only trigger if the status changed specifically to active
        if ($property->wasChanged('status') && in_array($property->status, ['active', 'active_listing'])) {
            DispatchPropertyMatch::dispatch($property);
        }
    }
}