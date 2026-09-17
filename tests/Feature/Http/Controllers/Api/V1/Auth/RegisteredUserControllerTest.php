<?php

namespace Tests\Feature\Http\Controllers\Api\V1\Auth;

use App\Models\User;
use App\Services\UserService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
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
        $creator = User::factory()->create();
        $token = $creator->createToken('creator')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/v1/auth/register', [
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
    }

    public function test_missing_input_returns_422_with_validation_errors(): void
    {
        $creator = User::factory()->create();
        $token = $creator->createToken('creator')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/v1/auth/register');

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    public function test_duplicate_email_returns_422_without_creating_a_user(): void
    {
        $creator = User::factory()->create();
        $token = $creator->createToken('creator')->plainTextToken;
        User::factory()->create(['email' => 'jane@example.com']);

        $response = $this->withToken($token)->postJson('/api/v1/auth/register', [
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
        $creator = User::factory()->create();
        $token = $creator->createToken('creator')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/v1/auth/register', [
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
        $creator = User::factory()->create();
        $token = $creator->createToken('creator')->plainTextToken;
        $this->mock(UserService::class)
            ->shouldReceive('create')
            ->once()
            ->andThrow(new RuntimeException('sensitive database detail'));

        $response = $this->withToken($token)->postJson('/api/v1/auth/register', [
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
    }
}
