<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Enums\RoleName;
use App\Http\Middleware\ResolveTenant;
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

    /**
     * @return array{User, Tenant, string}
     */
    private function tenantAdmin(): array
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($admin, ['status' => 'active']);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $admin->assignRole(RoleName::TenantAdmin->value);
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        return [$admin, $tenant, $admin->createToken('test')->plainTextToken];
    }
}
