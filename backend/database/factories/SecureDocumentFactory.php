<?php

namespace Database\Factories;

use App\Models\Agency;
use App\Models\User;
use App\Models\SecureDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

class SecureDocumentFactory extends Factory
{
    protected $model = SecureDocument::class;

    public function definition(): array
    {
        return [
            'agency_id'      => Agency::factory(),
            'uploaded_by'    => User::factory(),
            'user_id'        => null, // Can be set during test
            'type'           => $this->faker->randomElement([
                'title_deed', 'national_id', 'national_id_front', 
                'national_id_back', 'passport', 'kra_pin', 
                'selfie_verification', 'proof_of_address', 'contract'
            ]),
            's3_path'               => 'clients/' . $this->faker->uuid() . '.jpg',
            'status'                => 'pending_review',
            'ai_verification_status'=> 'pending',
            'ai_confidence_score'   => null,
            'ai_reasoning'          => null,
            'notes'                 => $this->faker->sentence(),
            'extracted_text'        => null,
            'ml_data'               => null,
        ];
    }
}