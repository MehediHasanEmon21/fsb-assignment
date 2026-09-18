<?php

namespace Tests\Feature\Http\Controllers\Api\V1\Auth;

use App\Enums\RoleName;
use App\Http\Middleware\ResolveTenant;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\UserService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RegisteredUserControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_public_registration_returns_401_without_creating_a_user(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'SecurePass1!',
            'password_confirmation' => 'SecurePass1!',
        ]);

        $response->assertUnauthorized();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_authenticated_user_can_create_a_user_with_201(): void
    {
        [$creator, $tenant, $token] = $this->authorizedCreator();

        $response = $this->withToken($token)
            ->withHeader(ResolveTenant::HEADER, (string) $tenant->id)
            ->postJson('/api/v1/auth/register', [
                'name' => '  Jane Doe  ',
                'email' => '  JANE@EXAMPLE.COM  ',
                'password' => 'SecurePass1!',
                'password_confirmation' => 'SecurePass1!',
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'User created successfully.')
            ->assertJsonPath('data.name', 'Jane Doe')
            ->assertJsonPath('data.email', 'jane@example.com')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonStructure(['data' => ['id', 'created_at']])
            ->assertJsonMissingPath('data.token')
            ->assertJsonMissing(['password', 'remember_token']);

        $user = User::query()->where('email', 'jane@example.com')->firstOrFail();

        $this->assertTrue(Hash::check('SecurePass1!', $user->password));
        $this->assertSame(0, $user->tokens()->count());
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->assertTrue($user->fresh()->hasRole(RoleName::User->value));
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }

    public function test_missing_input_returns_422_with_validation_errors(): void
    {
        [, $tenant, $token] = $this->authorizedCreator();

        $response = $this->withToken($token)
            ->withHeader(ResolveTenant::HEADER, (string) $tenant->id)
            ->postJson('/api/v1/auth/register');

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    public function test_duplicate_email_returns_422_without_creating_a_user(): void
    {
        [, $tenant, $token] = $this->authorizedCreator();
        User::factory()->create(['email' => 'jane@example.com']);

        $response = $this->withToken($token)
            ->withHeader(ResolveTenant::HEADER, (string) $tenant->id)
            ->postJson('/api/v1/auth/register', [
                'name' => 'Jane Duplicate',
                'email' => 'JANE@EXAMPLE.COM',
                'password' => 'SecurePass1!',
                'password_confirmation' => 'SecurePass1!',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_weak_password_returns_422_without_creating_a_user(): void
    {
        [, $tenant, $token] = $this->authorizedCreator();

        $response = $this->withToken($token)
            ->withHeader(ResolveTenant::HEADER, (string) $tenant->id)
            ->postJson('/api/v1/auth/register', [
                'name' => 'Jane Doe',
                'email' => 'jane@example.com',
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('password');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_unexpected_service_exception_returns_generic_500(): void
    {
        [, $tenant, $token] = $this->authorizedCreator();
        $exception = new RuntimeException('sensitive database detail');
        Log::spy();
        $this->mock(UserService::class)
            ->shouldReceive('create')
            ->once()
            ->andThrow($exception);

        $response = $this->withToken($token)
            ->withHeader(ResolveTenant::HEADER, (string) $tenant->id)
            ->postJson('/api/v1/auth/register', [
                'name' => 'Jane Doe',
                'email' => 'jane@example.com',
                'password' => 'SecurePass1!',
                'password_confirmation' => 'SecurePass1!',
            ]);

        $response->assertServerError()
            ->assertExactJson([
                'success' => false,
                'message' => 'Unable to create user.',
            ]);
        $response->assertDontSee('sensitive database detail');
        $this->assertDatabaseCount('users', 1);
        Log::shouldHaveReceived('error')
            ->once()
            ->with(
                'sensitive database detail',
                Mockery::on(
                    fn (array $context): bool => ($context['exception'] ?? null) === $exception,
                ),
            );
    }

    public function test_authenticated_user_must_select_a_tenant(): void
    {
        [, , $token] = $this->authorizedCreator();

        $this->withToken($token)
            ->postJson('/api/v1/auth/register', [])
            ->assertBadRequest();
    }

    public function test_tenant_member_without_users_create_permission_is_forbidden(): void
    {
        [, $tenant, $token] = $this->authorizedCreator(RoleName::User);

        $this->withToken($token)
            ->withHeader(ResolveTenant::HEADER, (string) $tenant->id)
            ->postJson('/api/v1/auth/register', [])
            ->assertForbidden();
    }

    public function test_user_creation_enforces_the_tenant_user_limit(): void
    {
        [, $tenant, $token] = $this->authorizedCreator(RoleName::TenantAdmin, 1);

        $this->withToken($token)
            ->withHeader(ResolveTenant::HEADER, (string) $tenant->id)
            ->postJson('/api/v1/auth/register', [
                'name' => 'Over Limit',
                'email' => 'over-limit@example.com',
                'password' => 'SecurePass1!',
                'password_confirmation' => 'SecurePass1!',
            ])
            ->assertUnprocessable();

        $this->assertDatabaseMissing('users', ['email' => 'over-limit@example.com']);
    }

    /**
     * @return array{User, Tenant, string}
     */
    private function authorizedCreator(
        RoleName $role = RoleName::TenantAdmin,
        int $userLimit = 10,
    ): array {
        $this->seed(RolePermissionSeeder::class);

        $creator = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $creator->tenants()->attach($tenant, ['status' => 'active']);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $creator->assignRole($role->value);
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

        return [$creator, $tenant, $creator->createToken('creator')->plainTextToken];
    }
}
