<?php

namespace Database\Factories;

use App\Models\Lead;
use App\Models\Agency;   
use App\Models\Property; 
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'phone' => $this->faker->phoneNumber(),
            'kanban_stage' => 'new',
            'lead_requirements' => ['message' => $this->faker->sentence()],
            'agency_id' => Agency::factory(),
            'property_id' => Property::factory(),
            'agent_id' => User::factory(), // Or Agent::factory(), depending on your models
        ];
    }
}
