<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use App\Services\UserAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class CurrentUserController extends Controller
{
    public function __construct(private readonly UserAccessService $access) {}

    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): JsonResponse
    {
        try {
            /** @var User $user */
            $user = $request->user();

            return $this->successResponse(
                [
                    ...UserResource::make($user)->resolve($request),
                    ...$this->access->summary($user),
                ],
                'Authenticated user retrieved successfully.',
            );
        } catch (Throwable $exception) {
            return $this->serverErrorResponse($exception, 'Unable to retrieve authenticated user.');
        }
    }
}
