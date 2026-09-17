<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Plan> */
class PlanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'slug' => fake()->unique()->slug(2),
            'description' => fake()->sentence(),
            'price' => fake()->randomFloat(2, 0, 500),
            'billing_interval' => fake()->randomElement(['monthly', 'yearly']),
            'status' => 'active',
        ];
    }
}
