<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\SubscriptionOperationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Subscription\UpsertSubscriptionRequest;
use App\Http\Resources\Api\V1\SubscriptionResource;
use App\Models\User;
use App\Services\SubscriptionService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class SubscriptionController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function show(Request $request, int $tenant): JsonResponse
    {
        try {
            /** @var User $user */
            $user = $request->user();
            $subscription = $this->subscriptions->current($user, $tenant);

            if ($subscription === null) {
                return $this->errorResponse('Active subscription not found.', 404);
            }

            return $this->successResponse(
                SubscriptionResource::make($subscription)->resolve($request),
                'Subscription retrieved successfully.',
            );
        } catch (ModelNotFoundException) {
            return $this->errorResponse('Active subscription not found.', 404);
        } catch (AuthorizationException) {
            return $this->errorResponse('Subscription access denied.', 403);
        } catch (Throwable $exception) {
            return $this->serverErrorResponse($exception, 'Unable to retrieve subscription.');
        }
    }

    public function update(UpsertSubscriptionRequest $request, int $tenant): JsonResponse
    {
        try {
            /** @var User $user */
            $user = $request->user();
            $subscription = $this->subscriptions->assign(
                $user,
                $tenant,
                $request->integer('plan_id'),
            );

            return $this->successResponse(
                SubscriptionResource::make($subscription)->resolve($request),
                'Subscription assigned successfully.',
            );
        } catch (ModelNotFoundException) {
            return $this->errorResponse('Tenant or plan not found.', 404);
        } catch (AuthorizationException) {
            return $this->errorResponse('Subscription management is not authorized.', 403);
        } catch (SubscriptionOperationException|DomainException $exception) {
            return $this->errorResponse($exception->getMessage(), 422);
        } catch (Throwable $exception) {
            return $this->serverErrorResponse($exception, 'Unable to assign subscription.');
        }
    }

    public function destroy(Request $request, int $tenant): JsonResponse
    {
        try {
            /** @var User $user */
            $user = $request->user();
            $subscription = $this->subscriptions->cancel($user, $tenant);

            return $this->successResponse(
                SubscriptionResource::make($subscription)->resolve($request),
                'Subscription cancelled successfully.',
            );
        } catch (ModelNotFoundException) {
            return $this->errorResponse('Tenant not found.', 404);
        } catch (AuthorizationException) {
            return $this->errorResponse('Subscription management is not authorized.', 403);
        } catch (SubscriptionOperationException $exception) {
            return $this->errorResponse($exception->getMessage(), 422);
        } catch (Throwable $exception) {
            return $this->serverErrorResponse($exception, 'Unable to cancel subscription.');
        }
    }
}
