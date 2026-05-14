<?php

namespace App\Services\Matching;

use App\Models\Lead;
use App\Models\Property;
use Illuminate\Database\Eloquent\Collection;

class PropertyMatchingService
{
    /**
     * Find properties that fit a specific buyer's requirements.
     */
    public function matchPropertiesForLead(Lead $lead): Collection
    {
        return Property::query()
            ->where('status', 'available')
            ->where('price', '<=', $lead->max_budget)
            ->where('price', '>=', $lead->min_budget)
            ->where('bedrooms', '>=', $lead->min_bedrooms)
            ->where('city', $lead->preferred_city)
            ->get();
    }

    /**
     * Find buyers who might be interested in a newly listed property.
     */
    public function matchLeadsForProperty(Property $property): Collection
    {
        return Lead::query()
            ->whereNotIn('kanban_stage', ['closed', 'lost'])
            ->where('max_budget', '>=', $property->price)
            ->where('min_bedrooms', '<=', $property->bedrooms)
            ->where('preferred_city', $property->city)
            ->get();
    }
}