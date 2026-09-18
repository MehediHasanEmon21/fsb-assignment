<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\FeatureUnavailableException;
use App\Exceptions\QuotaExceededException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Throwable;

class RegisteredUserController extends Controller
{
    public function __construct(private readonly UserService $users) {}

    public function store(RegisterRequest $request): JsonResponse
    {
        try {
            /** @var User $actor */
            $actor = $request->user();
            $user = $this->users->create(
                $actor,
                $request->safe()->only(['name', 'email', 'password']),
            );

            return $this->successResponse(
                UserResource::make($user)->resolve($request),
                'User created successfully.',
                201,
            );
        } catch (AuthorizationException) {
            return $this->errorResponse('User creation is not authorized.', 403);
        } catch (FeatureUnavailableException|QuotaExceededException $exception) {
            return $this->errorResponse($exception->getMessage(), 422);
        } catch (Throwable $exception) {
            return $this->serverErrorResponse($exception, 'Unable to create user.');
        }
    }
}
