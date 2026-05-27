<?php

namespace Database\Factories;

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
            'title'    => $this->faker->streetName() . ' Property',
            'price'    => $this->faker->numberBetween(150000, 850000),
            'location' => $this->faker->city(), // <-- This satisfies the strict 'location' requirement!
        ];
    }
}