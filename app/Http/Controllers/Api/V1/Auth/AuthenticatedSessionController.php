<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\InvalidCredentialsException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Services\AuthenticationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class AuthenticatedSessionController extends Controller
{
    public function __construct(private readonly AuthenticationService $authentication) {}

    public function store(LoginRequest $request): JsonResponse
    {
        try {
            $session = $this->authentication->login(
                $request->string('email')->toString(),
                $request->string('password')->toString(),
            );

            return $this->successResponse([
                'user' => UserResource::make($session['user'])->resolve($request),
                'token' => $session['token'],
            ], 'Login successful.');
        } catch (InvalidCredentialsException $exception) {
            return $this->errorResponse($exception->getMessage(), 401);
        } catch (Throwable $exception) {
            return $this->serverErrorResponse($exception, 'Unable to log in.');
        }
    }

    public function destroy(Request $request): JsonResponse
    {
        try {
            $this->authentication->logout($request->user());

            return $this->successResponse(null, 'Logout successful.');
        } catch (Throwable $exception) {
            return $this->serverErrorResponse($exception, 'Unable to log out.');
        }
    }
}
