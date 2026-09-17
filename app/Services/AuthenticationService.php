<?php

namespace App\Services;

use App\Exceptions\InvalidCredentialsException;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class AuthenticationService
{
    public const DEFAULT_TOKEN_NAME = 'api-token';

    /**
     * @return array{user: User, token: string}
     *
     * @throws InvalidCredentialsException
     */
    public function login(string $email, string $password, string $deviceName): array
    {
        $user = User::query()->where('email', $email)->first();

        if (
            $user === null
            || $user->status !== 'active'
            || ! Hash::check($password, $user->password)
        ) {
            throw new InvalidCredentialsException;
        }

        $tokenName = trim($deviceName) !== '' ? $deviceName : self::DEFAULT_TOKEN_NAME;

        return [
            'user' => $user,
            'token' => $user->createToken($tokenName)->plainTextToken,
        ];
    }

    public function logout(User $user): void
    {
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }
    }
}
