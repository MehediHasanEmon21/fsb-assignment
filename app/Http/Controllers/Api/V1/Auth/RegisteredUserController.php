<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Throwable;

class RegisteredUserController extends Controller
{
    public function __construct(private readonly UserService $users) {}

    public function store(RegisterRequest $request): JsonResponse
    {
        try {
            $user = $this->users->create(
                $request->safe()->only(['name', 'email', 'password']),
            );

            return $this->successResponse(
                UserResource::make($user)->resolve($request),
                'User created successfully.',
                201,
            );
        } catch (Throwable $exception) {
            return $this->serverErrorResponse($exception, 'Unable to create user.');
        }
    }
}
