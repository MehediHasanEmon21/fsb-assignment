<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\User;
use App\Tenancy\TenantContext;

class UserPolicy
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function assignTenantRole(User $actor, User $target): bool
    {
        if (! $this->tenantContext->has()) {
            return false;
        }

        $tenantId = $this->tenantContext->id();

        return $actor->accessibleTenants()->whereKey($tenantId)->exists()
            && $target->accessibleTenants()->whereKey($tenantId)->exists()
            && $actor->hasRole(RoleName::TenantAdmin->value)
            && $actor->can(PermissionName::UsersUpdate->value);
    }
}
