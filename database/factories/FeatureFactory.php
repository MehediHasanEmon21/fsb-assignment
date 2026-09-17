<?php

namespace Database\Factories;

use App\Models\Feature;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Feature> */
class FeatureFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'key' => fake()->unique()->slug(2),
            'description' => fake()->sentence(),
            'type' => fake()->randomElement(['boolean', 'limit']),
        ];
    }
}
