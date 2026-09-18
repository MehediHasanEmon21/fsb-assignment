<?php

namespace Tests\Feature\Security;

use App\Enums\RoleName;
use App\Http\Middleware\ResolveTenant;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function tearDown(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        parent::tearDown();
    }

    public function test_inactive_user_cannot_continue_using_an_existing_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('compromised-device');
        $user->update(['status' => 'inactive']);

        $this->withToken($token->plainTextToken)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $token->accessToken->id,
        ]);
    }

    public function test_expired_token_is_rejected(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('expired', ['api'], now()->subMinute());

        Auth::forgetGuards();

        $this->withToken($token->plainTextToken)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    public function test_token_without_api_ability_is_forbidden(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('limited', ['profile:read'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertForbidden();
    }

    public function test_user_creation_ignores_mass_assignment_and_privilege_injection(): void
    {
        [$admin, $tenant, $token] = $this->tenantAdmin();
        $otherTenant = Tenant::factory()->create();

        $response = $this->withToken($token)
            ->withHeader(ResolveTenant::HEADER, (string) $tenant->id)
            ->postJson('/api/v1/auth/register', [
                'name' => 'Injected User',
                'email' => 'injected@example.test',
                'password' => 'SecurePass1!',
                'password_confirmation' => 'SecurePass1!',
                'status' => 'inactive',
                'role' => RoleName::SuperAdmin->value,
                'tenant_id' => $otherTenant->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'active');

        $createdUser = User::query()->findOrFail($response->json('data.id'));
        $this->assertTrue($createdUser->tenants()->whereKey($tenant->id)->exists());
        $this->assertFalse($createdUser->tenants()->whereKey($otherTenant->id)->exists());

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->assertTrue($createdUser->hasRole(RoleName::User->value));
        $this->assertFalse($createdUser->hasRole(RoleName::SuperAdmin->value));
        $this->assertTrue($admin->hasRole(RoleName::TenantAdmin->value));
    }

    public function test_unapproved_sort_expression_is_rejected(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('security-test')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/plans?sort=price%20desc%2C%20(select%201)&direction=asc')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sort');
    }

    public function test_api_rate_limit_is_applied_to_non_login_routes(): void
    {
        config()->set('security.api_rate_limit_per_minute', 2);
        $user = User::factory()->create();
        $token = $user->createToken('rate-limit-test')->plainTextToken;

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->withToken($token)
                ->withServerVariables(['REMOTE_ADDR' => '192.0.2.14'])
                ->getJson('/api/v1/plans')
                ->assertOk();
        }

        $this->withToken($token)
            ->withServerVariables(['REMOTE_ADDR' => '192.0.2.14'])
            ->getJson('/api/v1/plans')
            ->assertTooManyRequests();
    }

    public function test_api_responses_include_security_headers(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'missing@example.test',
            'password' => 'invalid',
        ])->assertUnauthorized()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_uncaught_api_exception_is_sanitized_even_when_debug_is_enabled(): void
    {
        config()->set('app.debug', true);
        Route::middleware('api')->get('/api/v1/security/exception', function (): never {
            throw new RuntimeException('SQLSTATE secret-path /var/www/private.php');
        });

        $response = $this->getJson('/api/v1/security/exception');

        $response->assertInternalServerError()
            ->assertExactJson([
                'success' => false,
                'message' => 'An unexpected error occurred.',
            ])
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertDontSee('SQLSTATE')
            ->assertDontSee('/var/www/private.php')
            ->assertDontSee('trace');
    }

    /** @return array{User, Tenant, string} */
    private function tenantAdmin(): array
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
        $plan->features()->attach($feature, ['value' => '10']);
        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);

        return [$admin, $tenant, $admin->createToken('security-test')->plainTextToken];
    }
}
