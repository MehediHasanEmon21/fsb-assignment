<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Subscription> */
class SubscriptionFactory extends Factory
{
    public function definition(): array
    {
        $startsAt = fake()->dateTimeBetween('-6 months', 'now');

        return [
            'tenant_id' => Tenant::factory(),
            'plan_id' => Plan::factory(),
            'status' => 'active',
            'starts_at' => $startsAt,
            'ends_at' => fake()->dateTimeBetween($startsAt, '+1 year'),
            'cancelled_at' => null,
        ];
    }
}
