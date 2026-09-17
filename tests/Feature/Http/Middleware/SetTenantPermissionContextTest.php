<?php

namespace Tests\Feature\Http\Middleware;

use App\Enums\RoleName;
use App\Http\Middleware\ResolveTenant;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SetTenantPermissionContextTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['tenant', 'tenant.permissions', 'permission:dashboard.view'])
            ->get('/_testing/tenant-permission', fn (): array => ['authorized' => true]);
    }

    public function test_permission_middleware_uses_the_resolved_tenant_and_restores_context(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $firstTenant = Tenant::factory()->create();
        $secondTenant = Tenant::factory()->create();
        $user->tenants()->attach([$firstTenant->id, $secondTenant->id], ['status' => 'active']);

        app(PermissionRegistrar::class)->setPermissionsTeamId($firstTenant->id);
        $user->assignRole(RoleName::User->value);
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $this->actingAs($user)
            ->withHeader(ResolveTenant::HEADER, (string) $firstTenant->id)
            ->getJson('/_testing/tenant-permission')
            ->assertOk()
            ->assertExactJson(['authorized' => true]);

        $this->actingAs($user)
            ->withHeader(ResolveTenant::HEADER, (string) $secondTenant->id)
            ->getJson('/_testing/tenant-permission')
            ->assertForbidden();

        $this->assertNull(app(PermissionRegistrar::class)->getPermissionsTeamId());
    }

    public function test_super_admin_can_access_any_active_tenant_without_membership(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $superAdmin = User::factory()->create();
        $assignmentTenant = Tenant::factory()->create();
        $platformTenant = Tenant::factory()->create();

        app(PermissionRegistrar::class)->setPermissionsTeamId($assignmentTenant->id);
        $superAdmin->assignRole(RoleName::SuperAdmin->value);
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $this->actingAs($superAdmin)
            ->withHeader(ResolveTenant::HEADER, (string) $platformTenant->id)
            ->getJson('/_testing/tenant-permission')
            ->assertOk()
            ->assertExactJson(['authorized' => true]);
    }
}
