<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;

class CustomerPolicy
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function viewAny(User $user, Tenant $tenant): bool
    {
        return $this->hasTenantPermission($user, $tenant, PermissionName::CustomersView);
    }

    public function create(User $user, Tenant $tenant): bool
    {
        return $this->hasTenantPermission($user, $tenant, PermissionName::CustomersCreate);
    }

    public function view(User $user, Customer $customer): bool
    {
        return $this->hasCustomerPermission($user, $customer, PermissionName::CustomersView);
    }

    public function update(User $user, Customer $customer): bool
    {
        return $this->hasCustomerPermission($user, $customer, PermissionName::CustomersUpdate);
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $this->hasCustomerPermission($user, $customer, PermissionName::CustomersDelete);
    }

    private function hasCustomerPermission(
        User $user,
        Customer $customer,
        PermissionName $permission,
    ): bool {
        if (! $this->tenantContext->has()) {
            return false;
        }

        return $customer->tenant_id === $this->tenantContext->id()
            && $this->hasTenantPermission($user, $this->tenantContext->current(), $permission);
    }

    private function hasTenantPermission(
        User $user,
        Tenant $tenant,
        PermissionName $permission,
    ): bool {
        return $this->tenantContext->has()
            && $this->tenantContext->id() === (int) $tenant->getKey()
            && $tenant->status === 'active'
            && $user->accessibleTenants()->whereKey($tenant->getKey())->exists()
            && $user->can($permission->value);
    }
}
