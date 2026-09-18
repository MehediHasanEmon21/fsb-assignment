<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Enums\RoleName;
use App\Http\Middleware\ResolveTenant;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TenantControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function tearDown(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        parent::tearDown();
    }

    public function test_super_admin_can_create_a_tenant_with_an_initial_tenant_admin(): void
    {
        [, $token] = $this->platformAdmin();

        $response = $this->withToken($token)->postJson('/api/v1/tenants', [
            'name' => '  Acme Limited  ',
            'slug' => '  ACME-LIMITED  ',
            'email' => '  OWNER@ACME.TEST  ',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Acme Limited')
            ->assertJsonPath('data.slug', 'acme-limited')
            ->assertJsonPath('data.email', 'owner@acme.test')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonMissing(['password' => 'password']);

        $tenantId = $response->json('data.id');
        $tenantAdmin = User::query()->where('email', 'owner@acme.test')->firstOrFail();
        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => $tenantId,
            'user_id' => $tenantAdmin->id,
            'status' => 'active',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);
        $this->assertTrue($tenantAdmin->fresh()->hasRole(RoleName::TenantAdmin->value));
        $this->assertSame('Acme Limited', $tenantAdmin->name);
        $this->assertTrue(Hash::check('password', $tenantAdmin->password));
    }

    public function test_tenant_creation_requires_authentication_and_valid_unique_input(): void
    {
        Tenant::factory()->create(['slug' => 'existing']);

        $this->postJson('/api/v1/tenants', [
            'name' => 'Acme',
            'slug' => 'acme',
        ])->assertUnauthorized();

        [, $token] = $this->platformAdmin();

        $this->withToken($token)->postJson('/api/v1/tenants', [
            'name' => '',
            'slug' => 'existing',
            'email' => 'invalid',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'slug', 'email']);
    }

    public function test_regular_authenticated_user_cannot_create_a_tenant(): void
    {
        $user = User::factory()->create();

        $this->withToken($user->createToken('test')->plainTextToken)
            ->postJson('/api/v1/tenants', [
                'name' => 'Unauthorized Company',
                'slug' => 'unauthorized-company',
                'email' => 'unauthorized@example.com',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('tenants', ['slug' => 'unauthorized-company']);
    }

    public function test_tenant_email_must_be_available_for_the_tenant_admin_login(): void
    {
        [, $token] = $this->platformAdmin();
        User::factory()->create(['email' => 'existing@example.com']);

        $this->withToken($token)->postJson('/api/v1/tenants', [
            'name' => 'Acme',
            'slug' => 'acme',
            'email' => 'EXISTING@EXAMPLE.COM',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseMissing('tenants', ['slug' => 'acme']);
    }

    public function test_index_searches_sorts_and_paginates_only_accessible_tenants(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;
        $acme = Tenant::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
        $beta = Tenant::factory()->create(['name' => 'Beta', 'slug' => 'beta']);
        $inactive = Tenant::factory()->inactive()->create(['name' => 'Acme Old']);
        Tenant::factory()->create(['name' => 'Acme Hidden', 'slug' => 'hidden']);
        $user->tenants()->attach([$acme->id, $beta->id, $inactive->id], ['status' => 'active']);

        $response = $this->withToken($token)->getJson(
            '/api/v1/tenants?search=acme&sort=name&direction=desc&per_page=1',
        );

        $response->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.pagination.per_page', 1)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $acme->id);
    }

    public function test_index_rejects_unapproved_query_parameters(): void
    {
        $user = User::factory()->create();

        $this->withToken($user->createToken('test')->plainTextToken)
            ->getJson('/api/v1/tenants?sort=status&direction=random&per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sort', 'direction', 'per_page']);
    }

    public function test_tenant_admin_can_view_and_update_the_selected_tenant(): void
    {
        [$admin, $tenant, $token] = $this->tenantActor(RoleName::TenantAdmin);

        $this->withToken($token)
            ->withHeader(ResolveTenant::HEADER, (string) $tenant->id)
            ->getJson("/api/v1/tenants/{$tenant->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $tenant->id);

        $this->withToken($token)
            ->withHeader(ResolveTenant::HEADER, (string) $tenant->id)
            ->patchJson("/api/v1/tenants/{$tenant->id}", [
                'name' => '  Updated Company  ',
                'email' => '  UPDATED@EXAMPLE.COM  ',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Company')
            ->assertJsonPath('data.email', 'updated@example.com');

        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'name' => 'Updated Company',
        ]);
        $this->assertTrue($admin->accessibleTenants()->whereKey($tenant->id)->exists());
    }

    public function test_user_without_tenant_update_permission_is_forbidden(): void
    {
        [, $tenant, $token] = $this->tenantActor(RoleName::User);

        $this->withToken($token)
            ->withHeader(ResolveTenant::HEADER, (string) $tenant->id)
            ->patchJson("/api/v1/tenants/{$tenant->id}", ['name' => 'Forbidden'])
            ->assertForbidden();
    }

    public function test_tenant_admin_cannot_change_tenant_platform_status(): void
    {
        [, $tenant, $token] = $this->tenantActor(RoleName::TenantAdmin);

        $this->withToken($token)
            ->withHeader(ResolveTenant::HEADER, (string) $tenant->id)
            ->patchJson("/api/v1/tenants/{$tenant->id}", ['status' => 'inactive'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Tenant update is not authorized.');

        $this->assertDatabaseHas('tenants', [
            'id' => $tenant->id,
            'status' => 'active',
        ]);
    }

    public function test_route_tenant_cannot_be_swapped_to_another_tenant(): void
    {
        [, $tenant, $token] = $this->tenantActor(RoleName::TenantAdmin);
        $otherTenant = Tenant::factory()->create();

        $this->withToken($token)
            ->withHeader(ResolveTenant::HEADER, (string) $tenant->id)
            ->getJson("/api/v1/tenants/{$otherTenant->id}")
            ->assertNotFound()
            ->assertJsonPath('message', 'Tenant not found.');

        $this->withToken($token)
            ->withHeader(ResolveTenant::HEADER, (string) $otherTenant->id)
            ->getJson("/api/v1/tenants/{$otherTenant->id}")
            ->assertForbidden();
    }

    public function test_super_admin_can_reactivate_any_tenant(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $superAdmin = User::factory()->create();
        $assignmentTenant = Tenant::factory()->create();
        $platformTenant = Tenant::factory()->inactive()->create();
        $token = $superAdmin->createToken('test')->plainTextToken;

        app(PermissionRegistrar::class)->setPermissionsTeamId($assignmentTenant->id);
        $superAdmin->assignRole(RoleName::SuperAdmin->value);
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $this->withToken($token)
            ->withHeader(ResolveTenant::HEADER, (string) $platformTenant->id)
            ->patchJson("/api/v1/tenants/{$platformTenant->id}", [
                'name' => 'Platform Updated',
                'status' => 'active',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Platform Updated')
            ->assertJsonPath('data.status', 'active');
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

    /**
     * @return array{User, string}
     */
    private function platformAdmin(): array
    {
        $this->seed(RolePermissionSeeder::class);
        $superAdmin = User::factory()->create();
        $assignmentTenant = Tenant::factory()->create();
        app(PermissionRegistrar::class)->setPermissionsTeamId($assignmentTenant->id);
        $superAdmin->assignRole(RoleName::SuperAdmin->value);
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        return [$superAdmin, $superAdmin->createToken('test')->plainTextToken];
    }
}
