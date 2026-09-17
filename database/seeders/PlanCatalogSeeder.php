<?php

namespace Database\Seeders;

use App\Models\Feature;
use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $features = collect([
            ['name' => 'Users', 'key' => 'users', 'type' => 'limit'],
            ['name' => 'Customers', 'key' => 'customers', 'type' => 'limit'],
            ['name' => 'Analytics', 'key' => 'analytics', 'type' => 'boolean'],
        ])->mapWithKeys(function (array $attributes): array {
            $feature = Feature::query()->updateOrCreate(
                ['key' => $attributes['key']],
                $attributes,
            );

            return [$feature->key => $feature];
        });

        $plans = [
            'starter' => [
                'attributes' => ['name' => 'Starter', 'price' => 19, 'billing_interval' => 'monthly', 'status' => 'active'],
                'features' => ['users' => '5', 'customers' => '100', 'analytics' => 'false'],
            ],
            'professional' => [
                'attributes' => ['name' => 'Professional', 'price' => 79, 'billing_interval' => 'monthly', 'status' => 'active'],
                'features' => ['users' => '25', 'customers' => '1000', 'analytics' => 'true'],
            ],
        ];

        foreach ($plans as $slug => $configuration) {
            $plan = Plan::query()->updateOrCreate(
                ['slug' => $slug],
                $configuration['attributes'],
            );

            $plan->features()->sync(
                collect($configuration['features'])
                    ->mapWithKeys(fn (string $value, string $key): array => [
                        $features->get($key)->getKey() => ['value' => $value],
                    ])
                    ->all(),
            );
        }
    }
}
