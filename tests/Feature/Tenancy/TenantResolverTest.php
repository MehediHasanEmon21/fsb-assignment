<?php

namespace Tests\Feature\Tenancy;

use App\Enums\RoleName;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantResolver;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TenantResolverTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_resolves_an_active_tenant_through_an_active_membership(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        $resolvedTenant = app(TenantResolver::class)->resolve($user, $tenant->id);

        $this->assertTrue($resolvedTenant->is($tenant));
    }

    public function test_rejects_a_tenant_without_a_membership(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Tenant access denied.');

        app(TenantResolver::class)->resolve($user, $tenant->id);
    }

    public function test_rejects_an_inactive_membership(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $user->tenants()->attach($tenant, ['status' => 'inactive']);

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Tenant access denied.');

        app(TenantResolver::class)->resolve($user, $tenant->id);
    }

    public function test_rejects_an_inactive_tenant(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->inactive()->create();
        $user->tenants()->attach($tenant, ['status' => 'active']);

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Tenant access denied.');

        app(TenantResolver::class)->resolve($user, $tenant->id);
    }

    public function test_super_admin_can_resolve_any_active_tenant_without_membership(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $superAdmin = User::factory()->create();
        $assignmentTenant = Tenant::factory()->create();
        $platformTenant = Tenant::factory()->create();

        app(PermissionRegistrar::class)->setPermissionsTeamId($assignmentTenant->id);
        $superAdmin->assignRole(RoleName::SuperAdmin->value);
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $resolvedTenant = app(TenantResolver::class)->resolve($superAdmin, $platformTenant->id);

        $this->assertTrue($resolvedTenant->is($platformTenant));
    }
}
