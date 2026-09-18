<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Enums\RoleName;
use App\Http\Middleware\ResolveTenant;
use App\Models\Customer;
use App\Models\Feature;
use App\Models\FeatureUsage;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DashboardControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function tearDown(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        parent::tearDown();
    }

    public function test_dashboard_returns_correct_tenant_subscription_and_usage_metrics(): void
    {
        [, $tenant, $token, $subscription, $features] = $this->dashboardActor();
        $activeMember = User::factory()->create();
        $inactiveMember = User::factory()->create();
        $tenant->users()->attach($activeMember, ['status' => 'active']);
        $tenant->users()->attach($inactiveMember, ['status' => 'inactive']);
        Customer::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);
        Customer::factory()->create(['tenant_id' => $tenant->id, 'status' => 'inactive']);
        $otherTenant = Tenant::factory()->create();
        $otherTenant->users()->attach(User::factory()->create(), ['status' => 'active']);
        Customer::factory()->count(3)->create(['tenant_id' => $otherTenant->id]);

        FeatureUsage::factory()->create([
            'tenant_id' => $tenant->id,
            'feature_id' => $features['users']->id,
            'usage' => 2,
            'period_start' => $subscription->starts_at,
            'period_end' => $subscription->ends_at,
        ]);
        FeatureUsage::factory()->create([
            'tenant_id' => $tenant->id,
            'feature_id' => $features['customers']->id,
            'usage' => 2,
            'period_start' => $subscription->starts_at,
            'period_end' => $subscription->ends_at,
        ]);

        $response = $this->tenantRequest($token, $tenant)
            ->getJson("/api/v1/tenants/{$tenant->id}/dashboard");

        $response->assertOk()
            ->assertJsonPath('data.tenant.id', $tenant->id)
            ->assertJsonPath('data.metrics.users.total', 3)
            ->assertJsonPath('data.metrics.users.active', 2)
            ->assertJsonPath('data.metrics.users.inactive', 1)
            ->assertJsonPath('data.metrics.customers.total', 2)
            ->assertJsonPath('data.metrics.customers.active', 1)
            ->assertJsonPath('data.metrics.customers.inactive', 1)
            ->assertJsonPath('data.subscription.id', $subscription->id)
            ->assertJsonPath('data.subscription.plan.slug', 'dashboard-plan')
            ->assertJsonPath('data.features.0.key', 'users')
            ->assertJsonPath('data.features.0.usage', 2)
            ->assertJsonPath('data.features.0.limit', 5)
            ->assertJsonPath('data.features.0.remaining', 3)
            ->assertJsonPath('data.features.1.key', 'customers')
            ->assertJsonPath('data.features.1.remaining', 8)
            ->assertJsonPath('data.features.2.key', 'analytics')
            ->assertJsonPath('data.features.2.enabled', true)
            ->assertJsonPath('data.features.2.available', true)
            ->assertJsonPath('data.features.2.usage', null);
    }

    public function test_dashboard_without_active_subscription_returns_counts_and_empty_entitlements(): void
    {
        [, $tenant, $token] = $this->dashboardActor();
        Subscription::query()->forTenant($tenant)->delete();
        Customer::factory()->create(['tenant_id' => $tenant->id]);

        $this->tenantRequest($token, $tenant)
            ->getJson("/api/v1/tenants/{$tenant->id}/dashboard")
            ->assertOk()
            ->assertJsonPath('data.metrics.users.total', 1)
            ->assertJsonPath('data.metrics.customers.total', 1)
            ->assertJsonPath('data.subscription', null)
            ->assertJsonCount(0, 'data.features');
    }

    public function test_dashboard_requires_dashboard_permission(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($user, ['status' => 'active']);

        $this->tenantRequest($user->createToken('test')->plainTextToken, $tenant)
            ->getJson("/api/v1/tenants/{$tenant->id}/dashboard")
            ->assertForbidden();
    }

    public function test_dashboard_rejects_route_and_header_tenant_manipulation(): void
    {
        [, $tenant, $token] = $this->dashboardActor();
        $otherTenant = Tenant::factory()->create();

        $this->tenantRequest($token, $tenant)
            ->getJson("/api/v1/tenants/{$otherTenant->id}/dashboard")
            ->assertNotFound();

        $this->withToken($token)
            ->withHeader(ResolveTenant::HEADER, (string) $otherTenant->id)
            ->getJson("/api/v1/tenants/{$otherTenant->id}/dashboard")
            ->assertForbidden();
    }

    public function test_dashboard_uses_aggregate_counts_and_one_usage_query_for_all_features(): void
    {
        [, $tenant, $token] = $this->dashboardActor();
        $queries = collect();
        DB::listen(function (QueryExecuted $query) use ($queries): void {
            $queries->push(strtolower($query->sql));
        });

        $this->tenantRequest($token, $tenant)
            ->getJson("/api/v1/tenants/{$tenant->id}/dashboard")
            ->assertOk();

        $this->assertTrue($queries->contains(
            fn (string $sql): bool => str_contains($sql, 'from "customers"')
                && str_contains($sql, 'count(*)'),
        ));
        $this->assertTrue($queries->contains(
            fn (string $sql): bool => str_contains($sql, 'from "tenant_user"')
                && str_contains($sql, 'count(*)'),
        ));
        $this->assertCount(1, $queries->filter(
            fn (string $sql): bool => str_contains($sql, 'from "feature_usages"'),
        ));
        $this->assertFalse($queries->contains(
            fn (string $sql): bool => str_contains($sql, 'select * from "customers"'),
        ));
    }

    private function tenantRequest(string $token, Tenant $tenant): static
    {
        return $this->withToken($token)
            ->withHeader(ResolveTenant::HEADER, (string) $tenant->id);
    }

    /**
     * @return array{User, Tenant, string, Subscription, array<string, Feature>}
     */
    private function dashboardActor(): array
    {
        $this->seed(RolePermissionSeeder::class);
        $actor = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($actor, ['status' => 'active']);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $actor->assignRole(RoleName::User->value);
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $plan = Plan::factory()->create([
            'name' => 'Dashboard Plan',
            'slug' => 'dashboard-plan',
        ]);
        $features = [
            'users' => Feature::factory()->create([
                'name' => 'Users',
                'key' => 'users',
                'type' => 'limit',
            ]),
            'customers' => Feature::factory()->create([
                'name' => 'Customers',
                'key' => 'customers',
                'type' => 'limit',
            ]),
            'analytics' => Feature::factory()->create([
                'name' => 'Analytics',
                'key' => 'analytics',
                'type' => 'boolean',
            ]),
        ];
        $plan->features()->attach($features['users'], ['value' => '5']);
        $plan->features()->attach($features['customers'], ['value' => '10']);
        $plan->features()->attach($features['analytics'], ['value' => 'true']);
        $subscription = Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);

        return [
            $actor,
            $tenant,
            $actor->createToken('test')->plainTextToken,
            $subscription,
            $features,
        ];
    }
}
