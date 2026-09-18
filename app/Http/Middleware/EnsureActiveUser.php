<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveUser
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->status !== 'active') {
            $token = $user?->currentAccessToken();

            if ($token instanceof PersonalAccessToken) {
                $token->delete();
            }

            throw new AuthenticationException('Unauthenticated.');
        }

        return $next($request);
    }
}
