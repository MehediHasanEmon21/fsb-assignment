<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\FeatureUnavailableException;
use App\Exceptions\QuotaExceededException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Customer\IndexCustomerRequest;
use App\Http\Requests\Api\V1\Customer\StoreCustomerRequest;
use App\Http\Requests\Api\V1\Customer\UpdateCustomerRequest;
use App\Http\Resources\Api\V1\CustomerResource;
use App\Models\User;
use App\Services\CustomerService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class CustomerController extends Controller
{
    public function __construct(private readonly CustomerService $customers) {}

    public function index(IndexCustomerRequest $request, int $tenant): JsonResponse
    {
        try {
            /** @var User $user */
            $user = $request->user();
            $paginator = $this->customers->paginate($user, $tenant, $request->validated());

            return $this->paginatedResponse(
                $paginator,
                CustomerResource::collection($paginator->getCollection())->resolve($request),
                'Customers retrieved successfully.',
            );
        } catch (ModelNotFoundException) {
            return $this->errorResponse('Tenant not found.', 404);
        } catch (AuthorizationException) {
            return $this->errorResponse('Customer access denied.', 403);
        } catch (Throwable $exception) {
            return $this->serverErrorResponse($exception, 'Unable to retrieve customers.');
        }
    }

    public function store(StoreCustomerRequest $request, int $tenant): JsonResponse
    {
        try {
            /** @var User $user */
            $user = $request->user();
            $customer = $this->customers->create($user, $tenant, $request->validated());

            return $this->successResponse(
                CustomerResource::make($customer)->resolve($request),
                'Customer created successfully.',
                201,
            );
        } catch (ModelNotFoundException) {
            return $this->errorResponse('Tenant not found.', 404);
        } catch (AuthorizationException) {
            return $this->errorResponse('Customer creation is not authorized.', 403);
        } catch (FeatureUnavailableException|QuotaExceededException $exception) {
            return $this->errorResponse($exception->getMessage(), 422);
        } catch (Throwable $exception) {
            return $this->serverErrorResponse($exception, 'Unable to create customer.');
        }
    }

    public function show(Request $request, int $tenant, int $customer): JsonResponse
    {
        try {
            /** @var User $user */
            $user = $request->user();
            $customerModel = $this->customers->view($user, $tenant, $customer);

            return $this->successResponse(
                CustomerResource::make($customerModel)->resolve($request),
                'Customer retrieved successfully.',
            );
        } catch (ModelNotFoundException) {
            return $this->errorResponse('Customer not found.', 404);
        } catch (AuthorizationException) {
            return $this->errorResponse('Customer access denied.', 403);
        } catch (Throwable $exception) {
            return $this->serverErrorResponse($exception, 'Unable to retrieve customer.');
        }
    }

    public function update(
        UpdateCustomerRequest $request,
        int $tenant,
        int $customer,
    ): JsonResponse {
        try {
            /** @var User $user */
            $user = $request->user();
            $customerModel = $this->customers->update(
                $user,
                $tenant,
                $customer,
                $request->validated(),
            );

            return $this->successResponse(
                CustomerResource::make($customerModel)->resolve($request),
                'Customer updated successfully.',
            );
        } catch (ModelNotFoundException) {
            return $this->errorResponse('Customer not found.', 404);
        } catch (AuthorizationException) {
            return $this->errorResponse('Customer update is not authorized.', 403);
        } catch (Throwable $exception) {
            return $this->serverErrorResponse($exception, 'Unable to update customer.');
        }
    }

    public function destroy(Request $request, int $tenant, int $customer): JsonResponse
    {
        try {
            /** @var User $user */
            $user = $request->user();
            $this->customers->delete($user, $tenant, $customer);

            return $this->successResponse(null, 'Customer deleted successfully.');
        } catch (ModelNotFoundException) {
            return $this->errorResponse('Customer not found.', 404);
        } catch (AuthorizationException) {
            return $this->errorResponse('Customer deletion is not authorized.', 403);
        } catch (Throwable $exception) {
            return $this->serverErrorResponse($exception, 'Unable to delete customer.');
        }
    }
}
