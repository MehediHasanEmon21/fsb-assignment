<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class CurrentUserController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): JsonResponse
    {
        try {
            return $this->successResponse(
                UserResource::make($request->user())->resolve($request),
                'Authenticated user retrieved successfully.',
            );
        } catch (Throwable $exception) {
            return $this->serverErrorResponse($exception, 'Unable to retrieve authenticated user.');
        }
    }
}
