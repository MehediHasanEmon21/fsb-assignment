<?php

namespace Tests\Feature\Authorization;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RoleAssignmentService;
use App\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RolePermissionAuthorizationTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function tearDown(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        app(TenantContext::class)->forget();

        parent::tearDown();
    }

    public function test_roles_are_seeded_with_the_expected_permission_boundaries(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $superAdmin = Role::findByName(RoleName::SuperAdmin->value, 'sanctum');
        $tenantAdmin = Role::findByName(RoleName::TenantAdmin->value, 'sanctum');
        $manager = Role::findByName(RoleName::Manager->value, 'sanctum');
        $user = Role::findByName(RoleName::User->value, 'sanctum');

        $this->assertCount(0, $superAdmin->permissions);
        $this->assertCount(count(PermissionName::cases()), $tenantAdmin->permissions);
        $this->assertTrue($manager->hasPermissionTo(PermissionName::UsersCreate->value));
        $this->assertFalse($manager->hasPermissionTo(PermissionName::UsersDelete->value));
        $this->assertTrue($user->hasPermissionTo(PermissionName::TenantView->value));
        $this->assertFalse($user->hasPermissionTo(PermissionName::UsersCreate->value));
        $this->assertDatabaseMissing('roles', ['guard_name' => 'web']);
        $this->assertDatabaseMissing('permissions', ['guard_name' => 'web']);
        $this->assertSame(
            ['super admin', 'tenant admin', 'manager', 'user'],
            array_column(RoleName::cases(), 'value'),
        );
    }

    public function test_role_permission_seeder_is_idempotent(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->assertDatabaseCount('permissions', count(PermissionName::cases()));
        $this->assertDatabaseCount('roles', count(RoleName::cases()));
        $this->assertDatabaseCount('role_has_permissions', 26);
    }

    public function test_role_permissions_are_scoped_to_the_selected_tenant(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $firstTenant = Tenant::factory()->create();
        $secondTenant = Tenant::factory()->create();
        $user->tenants()->attach([$firstTenant->id, $secondTenant->id], ['status' => 'active']);

        $this->selectTenant($firstTenant);
        $user->assignRole(RoleName::Manager->value);
        $this->assertTrue($user->can(PermissionName::UsersCreate->value));

        $this->selectTenant($secondTenant, $user);
        $this->assertFalse($user->can(PermissionName::UsersCreate->value));
        $this->assertFalse($user->hasRole(RoleName::Manager->value));
    }

    public function test_tenant_policy_requires_permission_membership_and_matching_context(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $firstTenant = Tenant::factory()->create();
        $secondTenant = Tenant::factory()->create();
        $user->tenants()->attach([$firstTenant->id, $secondTenant->id], ['status' => 'active']);

        $this->selectTenant($firstTenant);
        $user->assignRole(RoleName::User->value);

        $this->assertTrue($user->can('view', $firstTenant));
        $this->assertFalse($user->can('update', $firstTenant));
        $this->assertFalse($user->can('view', $secondTenant));
    }

    public function test_tenant_admin_can_assign_manager_but_not_tenant_or_platform_admin(): void
    {
        $this->seed(RolePermissionSeeder::class);
        [$tenant, $actor, $target] = $this->tenantUsers();
        $this->selectTenant($tenant);
        $actor->assignRole(RoleName::TenantAdmin->value);

        app(RoleAssignmentService::class)->assign($actor, $target, $tenant, RoleName::Manager);
        $this->assertTrue($target->fresh()->hasRole(RoleName::Manager->value));

        foreach ([RoleName::TenantAdmin, RoleName::SuperAdmin] as $forbiddenRole) {
            try {
                app(RoleAssignmentService::class)->assign($actor, $target, $tenant, $forbiddenRole);
                $this->fail("{$forbiddenRole->value} should not be assignable by a tenant administrator.");
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_manager_cannot_assign_roles_even_with_user_update_permission(): void
    {
        $this->seed(RolePermissionSeeder::class);
        [$tenant, $actor, $target] = $this->tenantUsers();
        $this->selectTenant($tenant);
        $actor->assignRole(RoleName::Manager->value);

        $this->expectException(AuthorizationException::class);

        app(RoleAssignmentService::class)->assign($actor, $target, $tenant, RoleName::User);
    }

    public function test_super_admin_bypasses_permissions_and_can_manage_the_full_platform(): void
    {
        $this->seed(RolePermissionSeeder::class);
        [$firstTenant, $superAdmin, $target] = $this->tenantUsers();
        $secondTenant = Tenant::factory()->create();
        $target->tenants()->attach($secondTenant, ['status' => 'active']);

        $this->selectTenant($firstTenant);
        $superAdmin->assignRole(RoleName::SuperAdmin->value);

        $this->selectTenant($secondTenant, $superAdmin);
        $this->assertTrue($superAdmin->isSuperAdmin());
        $this->assertTrue($superAdmin->can('platform.unlisted-permission'));
        $this->assertTrue($superAdmin->can('update', $secondTenant));

        app(RoleAssignmentService::class)->assign($superAdmin, $target, $secondTenant, RoleName::Manager);
        $this->assertTrue($target->fresh()->hasRole(RoleName::Manager->value));

        $this->expectException(AuthorizationException::class);
        app(RoleAssignmentService::class)->assign($superAdmin, $target, $secondTenant, RoleName::SuperAdmin);
    }

    /**
     * @return array{Tenant, User, User}
     */
    private function tenantUsers(): array
    {
        $tenant = Tenant::factory()->create();
        $actor = User::factory()->create();
        $target = User::factory()->create();
        $actor->tenants()->attach($tenant, ['status' => 'active']);
        $target->tenants()->attach($tenant, ['status' => 'active']);

        return [$tenant, $actor, $target];
    }

    private function selectTenant(Tenant $tenant, ?User $user = null): void
    {
        app(TenantContext::class)->set($tenant);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $user?->unsetRelation('roles');
        $user?->unsetRelation('permissions');
    }
}
