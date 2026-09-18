<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Plan\IndexPlanRequest;
use App\Http\Resources\Api\V1\PlanResource;
use App\Services\PlanService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class PlanController extends Controller
{
    public function __construct(private readonly PlanService $plans) {}

    public function index(IndexPlanRequest $request): JsonResponse
    {
        try {
            $paginator = $this->plans->paginateAvailable($request->validated());

            return $this->paginatedResponse(
                $paginator,
                PlanResource::collection($paginator->getCollection())->resolve($request),
                'Plans retrieved successfully.',
            );
        } catch (Throwable $exception) {
            return $this->serverErrorResponse($exception, 'Unable to retrieve plans.');
        }
    }

    public function show(Request $request, int $plan): JsonResponse
    {
        try {
            return $this->successResponse(
                PlanResource::make($this->plans->findAvailable($plan))->resolve($request),
                'Plan retrieved successfully.',
            );
        } catch (ModelNotFoundException) {
            return $this->errorResponse('Plan not found.', 404);
        } catch (Throwable $exception) {
            return $this->serverErrorResponse($exception, 'Unable to retrieve plan.');
        }
    }
}
