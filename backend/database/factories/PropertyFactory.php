<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Property>
 */
class PropertyFactory extends Factory
{
    protected $model = Property::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'agency_id' => function (array $attributes) {
                return User::find($attributes['user_id'])->agency_id;
            },
            'title' => fake()->streetAddress() . ' Property',
            'price' => fake()->randomFloat(2, 50000, 5000000),
            'location' => fake()->address(),
            'city' => fake()->city(),
            // Added missing required fields based on your schema
            'bedrooms' => fake()->numberBetween(1, 6),
            'baths' => fake()->numberBetween(1, 4),
            'sqft' => fake()->numberBetween(500, 5000),
            'description' => fake()->paragraph(),
        ];
    }
}