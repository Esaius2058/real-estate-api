<?php

namespace App\Http\Resources\Property;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PropertyResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'price' => (float) $this->price,
            'location' => $this->location,
            'city' => $this->city,
            'bedrooms' => $this->bedrooms,
            'baths' => $this->baths,
            'sqft' => $this->sqft,
            'description' => $this->description,
            'status' => $this->status,
            'contract_end_date' => $this->contract_end_date,
            
            'images' => $this->whenLoaded('images'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
