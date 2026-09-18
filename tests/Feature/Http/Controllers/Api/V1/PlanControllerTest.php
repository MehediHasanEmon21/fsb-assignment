<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Models\Feature;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class PlanControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_plan_listing_requires_authentication(): void
    {
        $this->getJson('/api/v1/plans')->assertUnauthorized();
    }

    public function test_authenticated_user_can_filter_sort_and_paginate_available_plans(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;
        Plan::factory()->create([
            'name' => 'Team Monthly',
            'slug' => 'team-monthly',
            'price' => 29,
            'billing_interval' => 'monthly',
        ]);
        $expected = Plan::factory()->create([
            'name' => 'Business Monthly',
            'slug' => 'business-monthly',
            'price' => 99,
            'billing_interval' => 'monthly',
        ]);
        Plan::factory()->create([
            'name' => 'Business Annual',
            'slug' => 'business-annual',
            'price' => 999,
            'billing_interval' => 'yearly',
        ]);
        Plan::factory()->create([
            'name' => 'Business Legacy',
            'slug' => 'business-legacy',
            'price' => 9,
            'billing_interval' => 'monthly',
            'status' => 'inactive',
        ]);

        $response = $this->withToken($token)->getJson(
            '/api/v1/plans?search=business&billing_interval=monthly&sort=price&direction=desc&per_page=1',
        );

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.pagination.per_page', 1)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $expected->id);
    }

    public function test_authenticated_user_can_view_available_plan_details_with_features(): void
    {
        $user = User::factory()->create();
        $plan = Plan::factory()->create();
        $feature = Feature::factory()->create([
            'name' => 'Team members',
            'key' => 'team_members',
            'type' => 'limit',
        ]);
        $plan->features()->attach($feature, ['value' => '25']);

        $response = $this->withToken($user->createToken('test')->plainTextToken)
            ->getJson("/api/v1/plans/{$plan->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $plan->id)
            ->assertJsonPath('data.features.0.key', 'team_members')
            ->assertJsonPath('data.features.0.value', '25');
    }

    public function test_inactive_plan_is_not_available_and_query_parameters_are_validated(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;
        $inactive = Plan::factory()->create(['status' => 'inactive']);

        $this->withToken($token)
            ->getJson("/api/v1/plans/{$inactive->id}")
            ->assertNotFound();

        $this->withToken($token)
            ->getJson('/api/v1/plans?billing_interval=weekly&sort=status&direction=random&per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['billing_interval', 'sort', 'direction', 'per_page']);
    }
}
