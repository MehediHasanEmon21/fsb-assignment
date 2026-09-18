<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Tenant\IndexTenantMemberRequest;
use App\Http\Requests\Api\V1\Tenant\UpdateTenantMemberRequest;
use App\Http\Resources\Api\V1\TenantMemberResource;
use App\Models\User;
use App\Services\TenantMembershipService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Throwable;

class TenantMembershipController extends Controller
{
    public function __construct(private readonly TenantMembershipService $memberships) {}

    public function index(IndexTenantMemberRequest $request, int $tenant): JsonResponse
    {
        try {
            /** @var User $user */
            $user = $request->user();
            $paginator = $this->memberships->paginate($user, $tenant, $request->validated());

            return $this->paginatedResponse(
                $paginator,
                TenantMemberResource::collection($paginator->getCollection())->resolve($request),
                'Tenant members retrieved successfully.',
            );
        } catch (ModelNotFoundException) {
            return $this->errorResponse('Tenant not found.', 404);
        } catch (AuthorizationException) {
            return $this->errorResponse('Tenant member access denied.', 403);
        } catch (Throwable $exception) {
            return $this->serverErrorResponse($exception, 'Unable to retrieve tenant members.');
        }
    }

    public function update(
        UpdateTenantMemberRequest $request,
        int $tenant,
        int $member,
    ): JsonResponse {
        try {
            /** @var User $user */
            $user = $request->user();
            $updatedMember = $this->memberships->updateStatus(
                $user,
                $tenant,
                $member,
                $request->validated('status'),
            );

            return $this->successResponse(
                TenantMemberResource::make($updatedMember)->resolve($request),
                'Tenant membership updated successfully.',
            );
        } catch (ModelNotFoundException) {
            return $this->errorResponse('Tenant member not found.', 404);
        } catch (AuthorizationException) {
            return $this->errorResponse('Tenant membership update is not authorized.', 403);
        } catch (Throwable $exception) {
            return $this->serverErrorResponse($exception, 'Unable to update tenant membership.');
        }
    }
}
