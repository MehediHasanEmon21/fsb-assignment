<?php

namespace Database\Factories;

use App\Models\Feature;
use App\Models\FeatureUsage;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FeatureUsage> */
class FeatureUsageFactory extends Factory
{
    public function definition(): array
    {
        $periodStart = now()->startOfMonth();

        return [
            'tenant_id' => Tenant::factory(),
            'feature_id' => Feature::factory(),
            'usage' => fake()->numberBetween(0, 100),
            'period_start' => $periodStart,
            'period_end' => $periodStart->copy()->endOfMonth(),
        ];
    }
}
