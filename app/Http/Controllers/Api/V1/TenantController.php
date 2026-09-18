<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Tenant\IndexTenantRequest;
use App\Http\Requests\Api\V1\Tenant\StoreTenantRequest;
use App\Http\Requests\Api\V1\Tenant\UpdateTenantRequest;
use App\Http\Resources\Api\V1\TenantResource;
use App\Models\User;
use App\Services\TenantService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class TenantController extends Controller
{
    public function __construct(private readonly TenantService $tenants) {}

    public function index(IndexTenantRequest $request): JsonResponse
    {
        try {
            /** @var User $user */
            $user = $request->user();
            $paginator = $this->tenants->paginateAccessible($user, $request->validated());

            return $this->paginatedResponse(
                $paginator,
                TenantResource::collection($paginator->getCollection())->resolve($request),
                'Tenants retrieved successfully.',
            );
        } catch (Throwable $exception) {
            return $this->serverErrorResponse($exception, 'Unable to retrieve tenants.');
        }
    }

    public function store(StoreTenantRequest $request): JsonResponse
    {
        try {
            /** @var User $user */
            $user = $request->user();
            $tenant = $this->tenants->create($user, $request->validated());

            return $this->successResponse(
                TenantResource::make($tenant)->resolve($request),
                'Tenant created successfully.',
                201,
            );
        } catch (AuthorizationException) {
            return $this->errorResponse('Tenant creation is not authorized.', 403);
        } catch (Throwable $exception) {
            return $this->serverErrorResponse($exception, 'Unable to create tenant.');
        }
    }

    public function show(Request $request, int $tenant): JsonResponse
    {
        try {
            /** @var User $user */
            $user = $request->user();
            $tenantModel = $this->tenants->view($user, $tenant);

            return $this->successResponse(
                TenantResource::make($tenantModel)->resolve($request),
                'Tenant retrieved successfully.',
            );
        } catch (ModelNotFoundException) {
            return $this->errorResponse('Tenant not found.', 404);
        } catch (AuthorizationException) {
            return $this->errorResponse('Tenant access denied.', 403);
        } catch (Throwable $exception) {
            return $this->serverErrorResponse($exception, 'Unable to retrieve tenant.');
        }
    }

    public function update(UpdateTenantRequest $request, int $tenant): JsonResponse
    {
        try {
            /** @var User $user */
            $user = $request->user();
            $tenantModel = $this->tenants->update($user, $tenant, $request->validated());

            return $this->successResponse(
                TenantResource::make($tenantModel)->resolve($request),
                'Tenant updated successfully.',
            );
        } catch (ModelNotFoundException) {
            return $this->errorResponse('Tenant not found.', 404);
        } catch (AuthorizationException) {
            return $this->errorResponse('Tenant update is not authorized.', 403);
        } catch (Throwable $exception) {
            return $this->serverErrorResponse($exception, 'Unable to update tenant.');
        }
    }
}
