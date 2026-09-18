<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Enums\RoleName;
use App\Http\Middleware\ResolveTenant;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TenantMembershipControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function tearDown(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        parent::tearDown();
    }

    public function test_tenant_admin_can_search_filter_and_paginate_members(): void
    {
        [, $tenant, $token] = $this->tenantAdmin();
        $manager = User::factory()->create(['name' => 'Alice Manager']);
        $inactive = User::factory()->create(['name' => 'Alice Inactive']);
        $outsider = User::factory()->create(['name' => 'Alice Outsider']);
        $otherTenant = Tenant::factory()->create();
        $tenant->users()->attach($manager, ['status' => 'active']);
        $tenant->users()->attach($inactive, ['status' => 'inactive']);
        $otherTenant->users()->attach($outsider, ['status' => 'active']);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $manager->assignRole(RoleName::Manager->value);
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $response = $this->withToken($token)
            ->withHeader(ResolveTenant::HEADER, (string) $tenant->id)
            ->getJson("/api/v1/tenants/{$tenant->id}/members?search=alice&status=active&per_page=1");

        $response->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', $manager->id)
            ->assertJsonPath('data.items.0.membership_status', 'active')
            ->assertJsonPath('data.items.0.roles.0', RoleName::Manager->value)
            ->assertJsonMissing(['id' => $inactive->id])
            ->assertJsonMissing(['id' => $outsider->id]);

    }

    public function test_tenant_admin_can_change_an_existing_membership_status(): void
    {
        [, $tenant, $token] = $this->tenantAdmin();
        $member = User::factory()->create();
        $tenant->users()->attach($member, ['status' => 'active']);

        $this->withToken($token)
            ->withHeader(ResolveTenant::HEADER, (string) $tenant->id)
            ->patchJson("/api/v1/tenants/{$tenant->id}/members/{$member->id}", [
                'status' => 'inactive',
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $member->id)
            ->assertJsonPath('data.membership_status', 'inactive');

        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => $tenant->id,
            'user_id' => $member->id,
            'status' => 'inactive',
        ]);
    }

    public function test_member_from_another_tenant_cannot_be_managed(): void
    {
        [, $tenant, $token] = $this->tenantAdmin();
        $otherTenant = Tenant::factory()->create();
        $otherMember = User::factory()->create();
        $otherTenant->users()->attach($otherMember, ['status' => 'active']);

        $this->withToken($token)
            ->withHeader(ResolveTenant::HEADER, (string) $tenant->id)
            ->patchJson("/api/v1/tenants/{$tenant->id}/members/{$otherMember->id}", [
                'status' => 'inactive',
            ])
            ->assertNotFound()
            ->assertJsonPath('message', 'Tenant member not found.');

        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => $otherTenant->id,
            'user_id' => $otherMember->id,
            'status' => 'active',
        ]);
    }

    public function test_member_without_user_management_permission_is_forbidden(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $user->assignRole(RoleName::User->value);
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $this->withToken($user->createToken('test')->plainTextToken)
            ->withHeader(ResolveTenant::HEADER, (string) $tenant->id)
            ->getJson("/api/v1/tenants/{$tenant->id}/members")
            ->assertForbidden();
    }

    public function test_tenant_admin_can_change_a_member_role_without_assigning_admin_roles(): void
    {
        [, $tenant, $token] = $this->tenantAdmin();
        $member = User::factory()->create();
        $tenant->users()->attach($member, ['status' => 'active']);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $member->assignRole(RoleName::User->value);
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $this->withToken($token)
            ->withHeader(ResolveTenant::HEADER, (string) $tenant->id)
            ->patchJson("/api/v1/tenants/{$tenant->id}/members/{$member->id}/role", [
                'role' => RoleName::Manager->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.roles.0', RoleName::Manager->value);

        foreach ([RoleName::TenantAdmin, RoleName::SuperAdmin] as $role) {
            $this->withToken($token)
                ->withHeader(ResolveTenant::HEADER, (string) $tenant->id)
                ->patchJson("/api/v1/tenants/{$tenant->id}/members/{$member->id}/role", [
                    'role' => $role->value,
                ])
                ->assertForbidden();
        }
    }

    public function test_role_and_status_endpoints_reject_a_member_id_from_another_tenant(): void
    {
        [, $tenant, $token] = $this->tenantAdmin();
        $otherTenant = Tenant::factory()->create();
        $otherMember = User::factory()->create();
        $otherTenant->users()->attach($otherMember, ['status' => 'active']);

        $this->withToken($token)
            ->withHeader(ResolveTenant::HEADER, (string) $tenant->id)
            ->patchJson("/api/v1/tenants/{$tenant->id}/members/{$otherMember->id}/role", [
                'role' => RoleName::Manager->value,
            ])
            ->assertNotFound();

        $this->withToken($token)
            ->withHeader(ResolveTenant::HEADER, (string) $tenant->id)
            ->deleteJson("/api/v1/tenants/{$tenant->id}/members/{$otherMember->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => $otherTenant->id,
            'user_id' => $otherMember->id,
        ]);
    }

    public function test_membership_activation_enforces_the_user_limit(): void
    {
        [, $tenant, $token] = $this->tenantAdmin(1);
        $inactiveMember = User::factory()->create();
        $tenant->users()->attach($inactiveMember, ['status' => 'inactive']);

        $this->withToken($token)
            ->withHeader(ResolveTenant::HEADER, (string) $tenant->id)
            ->patchJson("/api/v1/tenants/{$tenant->id}/members/{$inactiveMember->id}", [
                'status' => 'active',
            ])
            ->assertUnprocessable();

        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => $tenant->id,
            'user_id' => $inactiveMember->id,
            'status' => 'inactive',
        ]);
    }

    public function test_tenant_admin_can_remove_a_member_without_deleting_global_identity(): void
    {
        [, $tenant, $token] = $this->tenantAdmin();
        $member = User::factory()->create();
        $tenant->users()->attach($member, ['status' => 'active']);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $member->assignRole(RoleName::User->value);
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $this->withToken($token)
            ->withHeader(ResolveTenant::HEADER, (string) $tenant->id)
            ->deleteJson("/api/v1/tenants/{$tenant->id}/members/{$member->id}")
            ->assertOk();

        $this->assertDatabaseMissing('tenant_user', [
            'tenant_id' => $tenant->id,
            'user_id' => $member->id,
        ]);
        $this->assertDatabaseHas('users', ['id' => $member->id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->assertFalse($member->fresh()->hasAnyRole(array_column(RoleName::cases(), 'value')));
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }

    public function test_tenant_admin_cannot_deactivate_their_own_membership(): void
    {
        [$admin, $tenant, $token] = $this->tenantAdmin();

        $this->withToken($token)
            ->withHeader(ResolveTenant::HEADER, (string) $tenant->id)
            ->patchJson("/api/v1/tenants/{$tenant->id}/members/{$admin->id}", [
                'status' => 'inactive',
            ])
            ->assertForbidden();
    }

    public function test_member_can_be_deactivated_without_an_active_subscription(): void
    {
        [, $tenant, $token] = $this->tenantAdmin();
        $member = User::factory()->create();
        $tenant->users()->attach($member, ['status' => 'active']);
        Subscription::query()->forTenant($tenant)->delete();

        $this->withToken($token)
            ->withHeader(ResolveTenant::HEADER, (string) $tenant->id)
            ->patchJson("/api/v1/tenants/{$tenant->id}/members/{$member->id}", [
                'status' => 'inactive',
            ])
            ->assertOk();

        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => $tenant->id,
            'user_id' => $member->id,
            'status' => 'inactive',
        ]);
    }

    /**
     * @return array{User, Tenant, string}
     */
    private function tenantAdmin(int $userLimit = 10): array
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($admin, ['status' => 'active']);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $admin->assignRole(RoleName::TenantAdmin->value);
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $plan = Plan::factory()->create();
        $feature = Feature::query()->firstOrCreate(
            ['key' => 'users'],
            ['name' => 'Users', 'type' => 'limit'],
        );
        $plan->features()->attach($feature, ['value' => (string) $userLimit]);
        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);

        return [$admin, $tenant, $admin->createToken('test')->plainTextToken];
    }
}
