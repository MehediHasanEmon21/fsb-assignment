<?php

namespace App\Services;

use App\Exceptions\FeatureConfigurationException;
use App\Exceptions\FeatureUnavailableException;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CustomerService
{
    private const FEATURE_KEY = 'customers';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly EntitlementService $entitlements,
    ) {}

    /**
     * @param  array{search?: ?string, status?: ?string, sort?: ?string, direction?: ?string, per_page?: ?int}  $filters
     */
    public function paginate(User $actor, int $tenantId, array $filters): LengthAwarePaginator
    {
        $tenant = $this->currentTenant($tenantId);
        Gate::forUser($actor)->authorize('viewAny', [Customer::class, $tenant]);
        $query = Customer::query()->forTenant($tenant);

        if ($search = $filters['search'] ?? null) {
            $query->where(function ($query) use ($search): void {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }

        $sort = $filters['sort'] ?? 'name';
        $direction = $filters['direction'] ?? 'asc';

        return $query
            ->orderBy($sort, $direction)
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();
    }

    /** @param array{name: string, email?: ?string, phone?: ?string, status?: string} $attributes */
    public function create(User $actor, int $tenantId, array $attributes): Customer
    {
        $tenant = $this->currentTenant($tenantId);
        Gate::forUser($actor)->authorize('create', [Customer::class, $tenant]);

        return DB::transaction(function () use ($tenant, $attributes): Customer {
            $tenant = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $this->synchronizeUsage($tenant);
            $this->entitlements->consume($tenant, self::FEATURE_KEY);

            return Customer::query()->create([
                'tenant_id' => $tenant->id,
                ...$attributes,
                'status' => $attributes['status'] ?? 'active',
            ]);
        });
    }

    public function view(User $actor, int $tenantId, int $customerId): Customer
    {
        $customer = $this->tenantCustomer($tenantId, $customerId);
        Gate::forUser($actor)->authorize('view', $customer);

        return $customer;
    }

    /** @param array{name?: string, email?: ?string, phone?: ?string, status?: string} $attributes */
    public function update(User $actor, int $tenantId, int $customerId, array $attributes): Customer
    {
        $customer = $this->tenantCustomer($tenantId, $customerId);
        Gate::forUser($actor)->authorize('update', $customer);
        $customer->update($attributes);

        return $customer->refresh();
    }

    public function delete(User $actor, int $tenantId, int $customerId): void
    {
        $customer = $this->tenantCustomer($tenantId, $customerId);
        Gate::forUser($actor)->authorize('delete', $customer);

        DB::transaction(function () use ($customer): void {
            $tenant = Tenant::query()->whereKey($customer->tenant_id)->lockForUpdate()->firstOrFail();
            $customer->delete();
            $this->synchronizeUsage($tenant, false);
        });
    }

    private function synchronizeUsage(Tenant $tenant, bool $required = true): void
    {
        try {
            $this->entitlements->synchronizeUsage(
                $tenant,
                self::FEATURE_KEY,
                Customer::query()->forTenant($tenant)->count(),
            );
        } catch (FeatureConfigurationException|FeatureUnavailableException $exception) {
            if ($required) {
                throw $exception;
            }
        }
    }

    private function tenantCustomer(int $tenantId, int $customerId): Customer
    {
        $tenant = $this->currentTenant($tenantId);

        return Customer::query()
            ->forTenant($tenant)
            ->whereKey($customerId)
            ->firstOrFail();
    }

    private function currentTenant(int $tenantId): Tenant
    {
        if (! $this->tenantContext->has() || $this->tenantContext->id() !== $tenantId) {
            throw (new ModelNotFoundException)->setModel(Tenant::class, [$tenantId]);
        }

        return $this->tenantContext->current();
    }
}
