<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Enums\RoleName;
use App\Http\Middleware\ResolveTenant;
use App\Models\Customer;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CustomerControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function tearDown(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        parent::tearDown();
    }

    public function test_authorized_member_can_create_a_tenant_customer(): void
    {
        [, $tenant, $token, $feature] = $this->tenantActor(RoleName::Manager);

        $this->tenantRequest($token, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->id}/customers", [
                'name' => '  Alice Customer  ',
                'email' => '  ALICE@EXAMPLE.COM  ',
                'phone' => '  +8801000000  ',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Alice Customer')
            ->assertJsonPath('data.email', 'alice@example.com')
            ->assertJsonPath('data.phone', '+8801000000')
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('customers', [
            'tenant_id' => $tenant->id,
            'email' => 'alice@example.com',
        ]);
        $this->assertDatabaseHas('feature_usages', [
            'tenant_id' => $tenant->id,
            'feature_id' => $feature->id,
            'usage' => 1,
        ]);
    }

    public function test_customer_listing_is_tenant_scoped_searchable_filterable_and_paginated(): void
    {
        [, $tenant, $token] = $this->tenantActor(RoleName::User);
        $otherTenant = Tenant::factory()->create();
        $expected = Customer::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Alice Active',
            'status' => 'active',
        ]);
        Customer::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Alice Inactive',
            'status' => 'inactive',
        ]);
        Customer::factory()->create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Alice Other',
            'status' => 'active',
        ]);

        $this->tenantRequest($token, $tenant)
            ->getJson(
                "/api/v1/tenants/{$tenant->id}/customers?search=alice&status=active&sort=name&direction=desc&per_page=1",
            )
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $expected->id);
    }

    public function test_customer_can_be_viewed_updated_and_deleted(): void
    {
        [, $tenant, $token, $feature] = $this->tenantActor(RoleName::TenantAdmin);
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);

        $this->tenantRequest($token, $tenant)
            ->getJson("/api/v1/tenants/{$tenant->id}/customers/{$customer->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $customer->id);

        $this->tenantRequest($token, $tenant)
            ->patchJson("/api/v1/tenants/{$tenant->id}/customers/{$customer->id}", [
                'name' => 'Updated Customer',
                'email' => null,
                'status' => 'inactive',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Customer')
            ->assertJsonPath('data.email', null)
            ->assertJsonPath('data.status', 'inactive');

        $this->tenantRequest($token, $tenant)
            ->deleteJson("/api/v1/tenants/{$tenant->id}/customers/{$customer->id}")
            ->assertOk();

        $this->assertDatabaseMissing('customers', ['id' => $customer->id]);
        $this->assertDatabaseHas('feature_usages', [
            'tenant_id' => $tenant->id,
            'feature_id' => $feature->id,
            'usage' => 0,
        ]);
    }

    public function test_customer_id_from_another_tenant_is_never_exposed_or_modified(): void
    {
        [, $tenant, $token] = $this->tenantActor(RoleName::TenantAdmin);
        $otherCustomer = Customer::factory()->create();
        $path = "/api/v1/tenants/{$tenant->id}/customers/{$otherCustomer->id}";

        $this->tenantRequest($token, $tenant)->getJson($path)->assertNotFound();
        $this->tenantRequest($token, $tenant)
            ->patchJson($path, ['name' => 'Manipulated'])
            ->assertNotFound();
        $this->tenantRequest($token, $tenant)->deleteJson($path)->assertNotFound();

        $this->assertDatabaseHas('customers', [
            'id' => $otherCustomer->id,
            'name' => $otherCustomer->name,
        ]);
    }

    public function test_customer_permissions_follow_the_seeded_role_boundaries(): void
    {
        [, $tenant, $managerToken] = $this->tenantActor(RoleName::Manager);
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);

        $this->tenantRequest($managerToken, $tenant)
            ->deleteJson("/api/v1/tenants/{$tenant->id}/customers/{$customer->id}")
            ->assertForbidden();

        [, $userTenant, $userToken] = $this->tenantActor(RoleName::User);

        $this->tenantRequest($userToken, $userTenant)
            ->postJson("/api/v1/tenants/{$userTenant->id}/customers", ['name' => 'Denied'])
            ->assertForbidden();
    }

    public function test_customer_quota_counts_existing_records_and_releases_after_delete(): void
    {
        [, $tenant, $token] = $this->tenantActor(RoleName::TenantAdmin, 2);
        $existing = Customer::factory()->create(['tenant_id' => $tenant->id]);

        $secondId = $this->tenantRequest($token, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->id}/customers", ['name' => 'Second'])
            ->assertCreated()
            ->json('data.id');

        $this->tenantRequest($token, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->id}/customers", ['name' => 'Over Limit'])
            ->assertUnprocessable();

        $this->tenantRequest($token, $tenant)
            ->deleteJson("/api/v1/tenants/{$tenant->id}/customers/{$existing->id}")
            ->assertOk();

        $this->tenantRequest($token, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->id}/customers", ['name' => 'Replacement'])
            ->assertCreated();

        $this->assertDatabaseHas('customers', ['id' => $secondId]);
        $this->assertSame(2, Customer::query()->forTenant($tenant)->count());
    }

    public function test_customer_creation_requires_an_active_customer_entitlement(): void
    {
        [$actor, $tenant, $token] = $this->tenantActor(RoleName::TenantAdmin);
        Subscription::query()->forTenant($tenant)->delete();

        $this->tenantRequest($token, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->id}/customers", ['name' => 'No Plan'])
            ->assertUnprocessable();

        $this->assertTrue($actor->accessibleTenants()->whereKey($tenant->id)->exists());
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_customer_can_be_deleted_without_an_active_subscription(): void
    {
        [, $tenant, $token] = $this->tenantActor(RoleName::TenantAdmin);
        $customer = Customer::factory()->create(['tenant_id' => $tenant->id]);
        Subscription::query()->forTenant($tenant)->delete();

        $this->tenantRequest($token, $tenant)
            ->deleteJson("/api/v1/tenants/{$tenant->id}/customers/{$customer->id}")
            ->assertOk();

        $this->assertDatabaseMissing('customers', ['id' => $customer->id]);
    }

    public function test_customer_validation_is_tenant_aware(): void
    {
        [, $tenant, $token] = $this->tenantActor(RoleName::TenantAdmin);
        Customer::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'duplicate@example.com',
        ]);

        $this->tenantRequest($token, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->id}/customers", [
                'name' => '',
                'email' => 'DUPLICATE@EXAMPLE.COM',
                'status' => 'unknown',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'status']);
    }

    private function tenantRequest(string $token, Tenant $tenant): static
    {
        return $this->withToken($token)
            ->withHeader(ResolveTenant::HEADER, (string) $tenant->id);
    }

    /**
     * @return array{User, Tenant, string, Feature}
     */
    private function tenantActor(RoleName $role, int $customerLimit = 10): array
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $user->assignRole($role->value);
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $plan = Plan::factory()->create();
        $feature = Feature::query()->firstOrCreate(
            ['key' => 'customers'],
            ['name' => 'Customers', 'type' => 'limit'],
        );
        $plan->features()->attach($feature, ['value' => (string) $customerLimit]);
        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);

        return [$user, $tenant, $user->createToken('test')->plainTextToken, $feature];
    }
}
