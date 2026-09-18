<?php

namespace Tests\Feature\Api;

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
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ComprehensiveApiBehaviorTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function tearDown(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        parent::tearDown();
    }

    public function test_framework_validation_errors_use_the_standard_api_error_envelope(): void
    {
        $user = User::factory()->create();

        $this->withToken($user->createToken('phase-15')->plainTextToken)
            ->getJson('/api/v1/plans?billing_interval=weekly&sort=status')
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'The selected billing interval is invalid. (and 1 more error)')
            ->assertJsonValidationErrors(['billing_interval', 'sort'])
            ->assertJsonMissingPath('data');
    }

    public function test_framework_authentication_and_authorization_errors_use_the_standard_api_error_envelope(): void
    {
        $this->getJson('/api/v1/plans')
            ->assertUnauthorized()
            ->assertExactJson([
                'success' => false,
                'message' => 'Unauthenticated.',
            ]);

        $user = User::factory()->create();

        $this->withToken($user->createToken('phase-15', ['profile:read'])->plainTextToken)
            ->getJson('/api/v1/plans')
            ->assertForbidden()
            ->assertExactJson([
                'success' => false,
                'message' => 'This action is unauthorized.',
            ]);
    }

    public function test_revoked_token_cannot_access_any_protected_route(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('revoked-device');
        $token->accessToken->delete();

        Auth::forgetGuards();

        $this->withToken($token->plainTextToken)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertExactJson([
                'success' => false,
                'message' => 'Unauthenticated.',
            ]);
    }

    public function test_inactive_tenant_membership_cannot_use_a_valid_role_permission_pair(): void
    {
        [$user, $tenant, $token] = $this->tenantActor(RoleName::TenantAdmin);
        $tenant->users()->updateExistingPivot($user->id, ['status' => 'inactive']);
        Customer::factory()->create(['tenant_id' => $tenant->id]);

        $this->tenantRequest($token, $tenant)
            ->getJson("/api/v1/tenants/{$tenant->id}/customers")
            ->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This action is unauthorized.');
    }

    public function test_customer_quota_is_enforced_without_partially_creating_the_next_customer(): void
    {
        [, $tenant, $token, $feature] = $this->tenantActor(RoleName::TenantAdmin, 1);

        $this->tenantRequest($token, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->id}/customers", ['name' => 'First Customer'])
            ->assertCreated()
            ->assertJsonPath('success', true);

        $this->tenantRequest($token, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->id}/customers", ['name' => 'Second Customer'])
            ->assertUnprocessable()
            ->assertJsonPath('success', false);

        $this->assertDatabaseMissing('customers', [
            'tenant_id' => $tenant->id,
            'name' => 'Second Customer',
        ]);
        $this->assertDatabaseHas('feature_usages', [
            'tenant_id' => $tenant->id,
            'feature_id' => $feature->id,
            'usage' => 1,
        ]);
    }

    public function test_super_admin_permission_bypass_does_not_bypass_missing_tenant_context(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $superAdmin = User::factory()->create();
        $roleTenant = Tenant::factory()->create();
        $targetTenant = Tenant::factory()->create();

        app(PermissionRegistrar::class)->setPermissionsTeamId($roleTenant->id);
        $superAdmin->assignRole(RoleName::SuperAdmin->value);
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $this->withToken($superAdmin->createToken('phase-15')->plainTextToken)
            ->getJson("/api/v1/tenants/{$targetTenant->id}/customers")
            ->assertBadRequest()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'A valid X-Tenant-ID header is required.');
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
        $tenant->users()->attach($user, ['status' => 'active']);

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

        return [$user, $tenant, $user->createToken('phase-15')->plainTextToken, $feature];
    }
}
