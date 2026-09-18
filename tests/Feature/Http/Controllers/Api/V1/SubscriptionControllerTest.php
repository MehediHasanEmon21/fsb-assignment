<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Enums\RoleName;
use App\Enums\SubscriptionStatus;
use App\Http\Middleware\ResolveTenant;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SubscriptionControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        parent::tearDown();
    }

    public function test_tenant_admin_can_assign_and_view_a_subscription(): void
    {
        CarbonImmutable::setTestNow('2026-01-31 10:00:00');
        [, $tenant, $token] = $this->tenantActor(RoleName::TenantAdmin);
        $plan = Plan::factory()->create([
            'name' => 'Launch',
            'billing_interval' => 'monthly',
        ]);

        $this->tenantRequest($token, $tenant)
            ->putJson("/api/v1/tenants/{$tenant->id}/subscription", ['plan_id' => $plan->id])
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.plan.id', $plan->id)
            ->assertJsonPath('data.ends_at', '2026-02-28T10:00:00.000000Z');

        $this->assertDatabaseHas('subscriptions', [
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
        ]);

        $this->tenantRequest($token, $tenant)
            ->getJson("/api/v1/tenants/{$tenant->id}/subscription")
            ->assertOk()
            ->assertJsonPath('data.plan.id', $plan->id);
    }

    public function test_changing_a_plan_cancels_the_previous_subscription_and_preserves_history(): void
    {
        CarbonImmutable::setTestNow('2026-04-01 08:00:00');
        [, $tenant, $token] = $this->tenantActor(RoleName::TenantAdmin);
        $monthly = Plan::factory()->create(['billing_interval' => 'monthly']);
        $yearly = Plan::factory()->create(['billing_interval' => 'yearly']);

        $this->tenantRequest($token, $tenant)
            ->putJson("/api/v1/tenants/{$tenant->id}/subscription", ['plan_id' => $monthly->id])
            ->assertOk();

        CarbonImmutable::setTestNow('2026-04-10 08:00:00');

        $this->tenantRequest($token, $tenant)
            ->putJson("/api/v1/tenants/{$tenant->id}/subscription", ['plan_id' => $yearly->id])
            ->assertOk()
            ->assertJsonPath('data.plan.id', $yearly->id)
            ->assertJsonPath('data.ends_at', '2027-04-10T08:00:00.000000Z');

        $this->assertDatabaseHas('subscriptions', [
            'tenant_id' => $tenant->id,
            'plan_id' => $monthly->id,
            'status' => 'cancelled',
            'ends_at' => '2026-04-10 08:00:00',
            'cancelled_at' => '2026-04-10 08:00:00',
        ]);
        $this->assertDatabaseHas('subscriptions', [
            'tenant_id' => $tenant->id,
            'plan_id' => $yearly->id,
            'status' => 'active',
        ]);
        $this->assertSame(2, Subscription::query()->whereBelongsTo($tenant)->count());
    }

    public function test_assigning_the_current_plan_is_idempotent(): void
    {
        [, $tenant, $token] = $this->tenantActor(RoleName::TenantAdmin);
        $plan = Plan::factory()->create();

        $firstId = $this->tenantRequest($token, $tenant)
            ->putJson("/api/v1/tenants/{$tenant->id}/subscription", ['plan_id' => $plan->id])
            ->assertOk()
            ->json('data.id');

        $this->tenantRequest($token, $tenant)
            ->putJson("/api/v1/tenants/{$tenant->id}/subscription", ['plan_id' => $plan->id])
            ->assertOk()
            ->assertJsonPath('data.id', $firstId);

        $this->assertSame(1, Subscription::query()->whereBelongsTo($tenant)->count());
    }

    public function test_inactive_or_unknown_plan_cannot_be_assigned(): void
    {
        [, $tenant, $token] = $this->tenantActor(RoleName::TenantAdmin);
        $inactive = Plan::factory()->create(['status' => 'inactive']);

        $this->tenantRequest($token, $tenant)
            ->putJson("/api/v1/tenants/{$tenant->id}/subscription", ['plan_id' => $inactive->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('plan_id');

        $this->tenantRequest($token, $tenant)
            ->putJson("/api/v1/tenants/{$tenant->id}/subscription", ['plan_id' => 999999])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('plan_id');
    }

    public function test_user_with_view_permission_can_view_but_cannot_manage_subscription(): void
    {
        [, $tenant, $token] = $this->tenantActor(RoleName::User);
        $plan = Plan::factory()->create();
        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);

        $this->tenantRequest($token, $tenant)
            ->getJson("/api/v1/tenants/{$tenant->id}/subscription")
            ->assertOk()
            ->assertJsonPath('data.plan.id', $plan->id);

        $this->tenantRequest($token, $tenant)
            ->putJson("/api/v1/tenants/{$tenant->id}/subscription", ['plan_id' => $plan->id])
            ->assertForbidden();

        $this->tenantRequest($token, $tenant)
            ->deleteJson("/api/v1/tenants/{$tenant->id}/subscription")
            ->assertForbidden();
    }

    public function test_subscription_access_is_isolated_by_active_tenant_context(): void
    {
        [, $tenant, $token] = $this->tenantActor(RoleName::TenantAdmin);
        $otherTenant = Tenant::factory()->create();
        $otherSubscription = Subscription::factory()->create(['tenant_id' => $otherTenant->id]);

        $this->tenantRequest($token, $tenant)
            ->getJson("/api/v1/tenants/{$otherTenant->id}/subscription")
            ->assertNotFound();

        $this->withToken($token)
            ->withHeader(ResolveTenant::HEADER, (string) $otherTenant->id)
            ->getJson("/api/v1/tenants/{$otherTenant->id}/subscription")
            ->assertForbidden();

        $this->assertDatabaseHas('subscriptions', [
            'id' => $otherSubscription->id,
            'status' => 'active',
        ]);
    }

    public function test_super_admin_can_manage_a_tenant_subscription_without_membership(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $superAdmin = User::factory()->create();
        $roleTenant = Tenant::factory()->create();
        $targetTenant = Tenant::factory()->create();
        $plan = Plan::factory()->create();

        app(PermissionRegistrar::class)->setPermissionsTeamId($roleTenant->id);
        $superAdmin->assignRole(RoleName::SuperAdmin->value);
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $this->tenantRequest(
            $superAdmin->createToken('test')->plainTextToken,
            $targetTenant,
        )->putJson(
            "/api/v1/tenants/{$targetTenant->id}/subscription",
            ['plan_id' => $plan->id],
        )->assertOk()
            ->assertJsonPath('data.plan.id', $plan->id);

        $this->assertDatabaseHas('subscriptions', [
            'tenant_id' => $targetTenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
        ]);
    }

    public function test_cancelling_a_subscription_ends_it_and_it_is_no_longer_current(): void
    {
        CarbonImmutable::setTestNow('2026-06-15 12:00:00');
        [, $tenant, $token] = $this->tenantActor(RoleName::TenantAdmin);
        $subscription = Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);

        $this->tenantRequest($token, $tenant)
            ->deleteJson("/api/v1/tenants/{$tenant->id}/subscription")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertDatabaseHas('subscriptions', [
            'id' => $subscription->id,
            'status' => 'cancelled',
            'ends_at' => '2026-06-15 12:00:00',
            'cancelled_at' => '2026-06-15 12:00:00',
        ]);

        $this->tenantRequest($token, $tenant)
            ->getJson("/api/v1/tenants/{$tenant->id}/subscription")
            ->assertNotFound();

        $this->tenantRequest($token, $tenant)
            ->deleteJson("/api/v1/tenants/{$tenant->id}/subscription")
            ->assertUnprocessable();
    }

    public function test_ended_active_subscription_is_expired_during_current_subscription_lookup(): void
    {
        CarbonImmutable::setTestNow('2026-08-01 00:00:00');
        [, $tenant, $token] = $this->tenantActor(RoleName::TenantAdmin);
        $subscription = Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => now()->subMonths(2),
            'ends_at' => now()->subMonth(),
        ]);

        $this->tenantRequest($token, $tenant)
            ->getJson("/api/v1/tenants/{$tenant->id}/subscription")
            ->assertNotFound();

        $this->assertDatabaseHas('subscriptions', [
            'id' => $subscription->id,
            'status' => 'expired',
        ]);
    }

    public function test_tenant_without_active_subscription_receives_not_found(): void
    {
        [, $tenant, $token] = $this->tenantActor(RoleName::TenantAdmin);

        $this->tenantRequest($token, $tenant)
            ->getJson("/api/v1/tenants/{$tenant->id}/subscription")
            ->assertNotFound()
            ->assertJsonPath('message', 'Active subscription not found.');
    }

    private function tenantRequest(string $token, Tenant $tenant): static
    {
        return $this->withToken($token)
            ->withHeader(ResolveTenant::HEADER, (string) $tenant->id);
    }

    /**
     * @return array{User, Tenant, string}
     */
    private function tenantActor(RoleName $role): array
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $user->assignRole($role->value);
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        return [$user, $tenant, $user->createToken('test')->plainTextToken];
    }
}
