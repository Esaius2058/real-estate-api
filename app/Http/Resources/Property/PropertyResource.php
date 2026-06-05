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

            // Flat array of URL strings — frontend reads property.images[0] directly
            'images' => $this->whenLoaded('images', fn() =>
                $this->images->pluck('s3_path')->filter()->values()->toArray()
            ),

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