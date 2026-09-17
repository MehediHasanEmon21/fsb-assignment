<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Feature;
use App\Models\FeatureUsage;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CoreDatabaseSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_core_tables_and_columns_exist(): void
    {
        $expectedColumns = [
            'tenants' => ['id', 'name', 'slug', 'email', 'status'],
            'tenant_user' => ['id', 'tenant_id', 'user_id', 'status'],
            'plans' => ['id', 'name', 'slug', 'description', 'price', 'billing_interval', 'status'],
            'features' => ['id', 'name', 'key', 'description', 'type'],
            'plan_features' => ['id', 'plan_id', 'feature_id', 'value'],
            'subscriptions' => ['id', 'tenant_id', 'plan_id', 'status', 'starts_at', 'ends_at', 'cancelled_at'],
            'feature_usages' => ['id', 'tenant_id', 'feature_id', 'usage', 'period_start', 'period_end'],
            'customers' => ['id', 'tenant_id', 'name', 'email', 'phone', 'status'],
        ];

        foreach ($expectedColumns as $table => $columns) {
            $this->assertTrue(Schema::hasColumns($table, $columns), "Missing expected columns on {$table}.");
        }
    }

    public function test_users_can_belong_to_multiple_tenants_with_membership_status(): void
    {
        $user = User::factory()->create();
        $tenants = Tenant::factory()->count(2)->create();

        $user->tenants()->attach($tenants->first(), ['status' => 'active']);
        $user->tenants()->attach($tenants->last(), ['status' => 'invited']);

        $this->assertCount(2, $user->tenants);
        $this->assertSame('invited', $user->tenants->firstWhere('id', $tenants->last()->id)->pivot->status);
    }

    public function test_duplicate_tenant_memberships_are_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create();
        $tenant->users()->attach($user, ['status' => 'active']);

        $this->expectException(QueryException::class);

        $tenant->users()->attach($user, ['status' => 'active']);
    }

    public function test_customer_email_must_be_unique_within_a_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        Customer::factory()->for($tenant)->create(['email' => 'customer@example.com']);

        $this->expectException(QueryException::class);

        Customer::factory()->for($tenant)->create(['email' => 'customer@example.com']);
    }

    public function test_customer_email_can_be_reused_across_tenants_or_omitted(): void
    {
        $tenants = Tenant::factory()->count(2)->create();

        Customer::factory()->for($tenants->first())->create(['email' => 'shared@example.com']);
        Customer::factory()->for($tenants->last())->create(['email' => 'shared@example.com']);
        Customer::factory()->for($tenants->first())->count(2)->create(['email' => null]);

        $this->assertDatabaseCount('customers', 4);
    }

    public function test_core_models_expose_their_business_relationships(): void
    {
        $tenant = Tenant::factory()->create();
        $plan = Plan::factory()->create();
        $feature = Feature::factory()->create();
        $plan->features()->attach($feature, ['value' => '10']);

        $customer = Customer::factory()->for($tenant)->create();
        $subscription = Subscription::factory()->for($tenant)->for($plan)->create();
        $usage = FeatureUsage::factory()->for($tenant)->for($feature)->create();

        $this->assertTrue($tenant->customers->contains($customer));
        $this->assertTrue($tenant->subscriptions->contains($subscription));
        $this->assertTrue($tenant->featureUsages->contains($usage));
        $this->assertTrue($plan->features->contains($feature));
        $this->assertSame('10', $plan->features->first()->pivot->value);
        $this->assertTrue($feature->plans->contains($plan));
        $this->assertTrue($customer->tenant->is($tenant));
        $this->assertTrue($subscription->plan->is($plan));
    }

    public function test_plan_catalog_seeder_is_idempotent(): void
    {
        $this->seed(PlanCatalogSeeder::class);
        $this->seed(PlanCatalogSeeder::class);

        $this->assertDatabaseCount('plans', 2);
        $this->assertDatabaseCount('features', 3);
        $this->assertDatabaseCount('plan_features', 6);
        $this->assertDatabaseHas('plan_features', [
            'plan_id' => Plan::query()->where('slug', 'professional')->value('id'),
            'feature_id' => Feature::query()->where('key', 'analytics')->value('id'),
            'value' => 'true',
        ]);
    }
}
