<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;

class TenantPolicy
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function view(User $user, Tenant $tenant): bool
    {
        return $this->isActiveTenantMember($user, $tenant)
            && $user->can(PermissionName::TenantView->value);
    }

    public function update(User $user, Tenant $tenant): bool
    {
        return $this->isActiveTenantMember($user, $tenant)
            && $user->can(PermissionName::TenantUpdate->value);
    }

    private function isActiveTenantMember(User $user, Tenant $tenant): bool
    {
        return $this->tenantContext->has()
            && $this->tenantContext->id() === (int) $tenant->getKey()
            && $tenant->status === 'active'
            && $user->accessibleTenants()->whereKey($tenant->getKey())->exists();
    }
}
