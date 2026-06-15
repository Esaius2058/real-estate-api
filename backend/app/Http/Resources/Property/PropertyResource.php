<?php

namespace App\Http\Resources\Property;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PropertyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'agency_id'         => $this->agency_id,
            'user_id'           => $this->user_id,
            'title'             => $this->title,
            'price'             => (float) $this->price,
            'location'          => $this->location,
            'city'              => $this->city,
            'bedrooms'          => $this->bedrooms,
            'baths'             => $this->baths,
            'sqft'              => $this->sqft,
            'description'       => $this->description,
            'status'            => $this->status,
            'contract_end_date' => $this->contract_end_date,
            
            // Safe fallback if the column doesn't exist yet or is null
            'amenities'         => $this->amenities ?? [], 

            // AI/Categorized Image Mapping
            'images' => $this->whenLoaded('images', function() {
                return [
                    'main'     => $this->images->where('is_primary', 1)->first()?->s3_path,
                    'interior' => array_values($this->images->where('is_primary', 0)->pluck('s3_path')->toArray()),
                    'exterior' => [], // Included to satisfy React TypeScript interfaces
                ];
            }, [
                // Fallback structure if images relation is not loaded
                'main' => null,
                'interior' => [],
                'exterior' => []
            ]),

            // Agent details when loaded via ->load('agent')
            'agent' => $this->whenLoaded('agent', fn() => [
                'id'    => $this->agent->id,
                'name'  => $this->agent->name,
                'email' => $this->agent->email,
            ]),

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}