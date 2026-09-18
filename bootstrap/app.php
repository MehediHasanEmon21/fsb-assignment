<?php

use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetTenantPermissionContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(append: [
            'throttle:api',
            SecurityHeaders::class,
        ]);
        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'active.user' => EnsureActiveUser::class,
            'permission' => PermissionMiddleware::class,
            'role' => RoleMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'tenant' => ResolveTenant::class,
            'tenant.permissions' => SetTenantPermissionContext::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
                'errors' => $exception->errors(),
            ], $exception->status);
        });
        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 401);
        });
        $exceptions->render(function (AuthorizationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $message = $exception->getMessage() === 'Tenant access denied.'
                ? 'Tenant access denied.'
                : 'This action is unauthorized.';

            return response()->json([
                'success' => false,
                'message' => $message,
            ], 403);
        });
        $exceptions->respond(function (Response $response): Response {
            if (! request()->is('api/*')) {
                return $response;
            }

            $payload = json_decode((string) $response->getContent(), true);

            if (
                $response->getStatusCode() >= 400
                && $response->getStatusCode() < 500
                && (! is_array($payload) || ! array_key_exists('success', $payload))
            ) {
                $message = is_array($payload) && is_string($payload['message'] ?? null)
                    ? $payload['message']
                    : Response::$statusTexts[$response->getStatusCode()];

                if ($response->getStatusCode() === 403) {
                    $message = 'This action is unauthorized.';
                }

                $response = response()->json([
                    'success' => false,
                    'message' => $message,
                ], $response->getStatusCode());
            }

            if ($response->getStatusCode() >= 500) {
                $payload = json_decode((string) $response->getContent(), true);

                if (! is_array($payload) || ($payload['success'] ?? null) !== false) {
                    $response = response()->json([
                        'success' => false,
                        'message' => 'An unexpected error occurred.',
                    ], 500);
                }
            }

            return SecurityHeaders::apply($response);
        });
    })->create();
