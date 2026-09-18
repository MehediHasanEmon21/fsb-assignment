<?php

namespace Tests\Feature\Http\Controllers\Api\V1\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AuthenticatedSessionControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_valid_credentials_return_a_persisted_bearer_token(): void
    {
        $user = User::factory()->create([
            'email' => 'jane@example.com',
            'password' => 'password',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => '  JANE@EXAMPLE.COM ',
            'password' => 'password',
            'device_name' => '  integration-test  ',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Login successful.')
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.email', 'jane@example.com')
            ->assertJsonStructure(['data' => ['token']])
            ->assertJsonMissing(['password', 'remember_token']);

        $this->assertSame('integration-test', $user->tokens()->value('name'));
        $this->assertSame(['api'], $user->tokens()->firstOrFail()->abilities);
        $this->assertNotNull($user->tokens()->firstOrFail()->expires_at);
        $this->assertStringContainsString('|saas_', $response->json('data.token'));
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_login_without_a_device_name_uses_the_default_token_name(): void
    {
        $user = User::factory()->create([
            'email' => 'jane@example.com',
            'password' => 'password',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'jane@example.com',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['token']]);
        $this->assertSame('api-token', $user->tokens()->value('name'));
    }

    public function test_invalid_credentials_return_401_without_creating_a_token(): void
    {
        User::factory()->create([
            'email' => 'jane@example.com',
            'password' => 'correct-password',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'jane@example.com',
            'password' => 'wrong-password',
            'device_name' => 'integration-test',
        ]);

        $response->assertUnauthorized()
            ->assertExactJson([
                'success' => false,
                'message' => 'The provided credentials are incorrect.',
            ]);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_inactive_user_receives_the_same_401_as_invalid_credentials(): void
    {
        User::factory()->inactive()->create([
            'email' => 'jane@example.com',
            'password' => 'password',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'jane@example.com',
            'password' => 'password',
            'device_name' => 'integration-test',
        ]);

        $response->assertUnauthorized()
            ->assertExactJson([
                'success' => false,
                'message' => 'The provided credentials are incorrect.',
            ]);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_sixth_login_attempt_for_an_email_and_ip_returns_429(): void
    {
        $credentials = [
            'email' => 'missing@example.com',
            'password' => 'wrong-password',
            'device_name' => 'integration-test',
        ];

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', $credentials)->assertUnauthorized();
        }

        $this->postJson('/api/v1/auth/login', $credentials)->assertTooManyRequests();
    }

    public function test_logout_revokes_only_the_current_token_and_reuse_returns_401(): void
    {
        $user = User::factory()->create();
        $currentToken = $user->createToken('current-device');
        $otherToken = $user->createToken('other-device');

        $response = $this->withToken($currentToken->plainTextToken)
            ->postJson('/api/v1/auth/logout');

        $response->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => 'Logout successful.',
                'data' => null,
            ]);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $currentToken->accessToken->id,
        ]);
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $otherToken->accessToken->id,
        ]);

        Auth::forgetGuards();

        $this->withToken($currentToken->plainTextToken)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
        $this->withToken($otherToken->plainTextToken)
            ->getJson('/api/v1/auth/me')
            ->assertOk();
    }
}
