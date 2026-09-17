<?php

namespace Database\Factories;

use App\Models\Feature;
use App\Models\Plan;
use App\Models\PlanFeature;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PlanFeature> */
class PlanFeatureFactory extends Factory
{
    public function definition(): array
    {
        return [
            'plan_id' => Plan::factory(),
            'feature_id' => Feature::factory(),
            'value' => (string) fake()->numberBetween(0, 1000),
        ];
    }
}
