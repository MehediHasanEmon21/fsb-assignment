<?php

namespace Tests\Feature\Http\Controllers\Api\V1\Auth;

use App\Enums\RoleName;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CurrentUserControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_request_without_a_token_returns_401(): void
    {
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_valid_token_returns_only_the_public_user_fields(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('integration-test')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/v1/auth/me');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Authenticated user retrieved successfully.')
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.name', $user->name)
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.is_super_admin', false)
            ->assertJsonPath('data.tenant_id', null)
            ->assertJsonPath('data.role', null)
            ->assertJsonCount(0, 'data.tenants')
            ->assertJsonStructure(['data' => ['email_verified_at', 'created_at']])
            ->assertJsonMissing(['password', 'remember_token']);
    }

    public function test_single_tenant_user_receives_tenant_id_role_and_accessible_tenants(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create([
            'name' => 'Acme Software Ltd',
            'slug' => 'acme-software',
        ]);
        $user->tenants()->attach($tenant, ['status' => 'active']);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $user->assignRole(RoleName::TenantAdmin->value);
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $this->withToken($user->createToken('integration-test')->plainTextToken)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.is_super_admin', false)
            ->assertJsonPath('data.tenant_id', $tenant->id)
            ->assertJsonPath('data.role', RoleName::TenantAdmin->value)
            ->assertJsonPath('data.tenants.0.id', $tenant->id)
            ->assertJsonPath('data.tenants.0.name', 'Acme Software Ltd')
            ->assertJsonPath('data.tenants.0.slug', 'acme-software')
            ->assertJsonPath('data.tenants.0.role', RoleName::TenantAdmin->value);
    }

    public function test_multiple_tenants_require_client_selection_and_return_each_tenant_role(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $acme = Tenant::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
        $northwind = Tenant::factory()->create(['name' => 'Northwind', 'slug' => 'northwind']);

        foreach ([
            [$acme, RoleName::Manager],
            [$northwind, RoleName::User],
        ] as [$tenant, $role]) {
            $user->tenants()->attach($tenant, ['status' => 'active']);
            app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
            $user->assignRole($role->value);
        }
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $this->withToken($user->createToken('integration-test')->plainTextToken)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.tenant_id', null)
            ->assertJsonPath('data.role', null)
            ->assertJsonCount(2, 'data.tenants')
            ->assertJsonPath('data.tenants.0.id', $acme->id)
            ->assertJsonPath('data.tenants.0.role', RoleName::Manager->value)
            ->assertJsonPath('data.tenants.1.id', $northwind->id)
            ->assertJsonPath('data.tenants.1.role', RoleName::User->value);
    }

    public function test_super_admin_has_platform_role_without_a_fake_tenant_membership(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $roleTenant = Tenant::factory()->create();

        app(PermissionRegistrar::class)->setPermissionsTeamId($roleTenant->id);
        $user->assignRole(RoleName::SuperAdmin->value);
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $this->withToken($user->createToken('integration-test')->plainTextToken)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.is_super_admin', true)
            ->assertJsonPath('data.tenant_id', null)
            ->assertJsonPath('data.role', RoleName::SuperAdmin->value)
            ->assertJsonCount(0, 'data.tenants');
    }
}
