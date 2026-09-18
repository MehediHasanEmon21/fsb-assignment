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

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, Tenant $tenant): bool
    {
        return $this->isActiveTenantMember($user, $tenant)
            && $user->can(PermissionName::TenantUpdate->value);
    }

    public function viewMembers(User $user, Tenant $tenant): bool
    {
        return $this->isActiveTenantMember($user, $tenant)
            && $user->can(PermissionName::UsersView->value);
    }

    public function updateMember(User $user, Tenant $tenant): bool
    {
        return $this->isActiveTenantMember($user, $tenant)
            && $user->can(PermissionName::UsersUpdate->value);
    }

    public function createMember(User $user, Tenant $tenant): bool
    {
        return $this->isActiveTenantMember($user, $tenant)
            && $user->can(PermissionName::UsersCreate->value);
    }

    public function deleteMember(User $user, Tenant $tenant): bool
    {
        return $this->isActiveTenantMember($user, $tenant)
            && $user->can(PermissionName::UsersDelete->value);
    }

    public function viewSubscription(User $user, Tenant $tenant): bool
    {
        return $this->isActiveTenantMember($user, $tenant)
            && $user->can(PermissionName::SubscriptionView->value);
    }

    public function manageSubscription(User $user, Tenant $tenant): bool
    {
        return $this->isActiveTenantMember($user, $tenant)
            && $user->can(PermissionName::SubscriptionManage->value);
    }

    public function viewDashboard(User $user, Tenant $tenant): bool
    {
        return $this->isActiveTenantMember($user, $tenant)
            && $user->can(PermissionName::DashboardView->value);
    }

    private function isActiveTenantMember(User $user, Tenant $tenant): bool
    {
        return $this->tenantContext->has()
            && $this->tenantContext->id() === (int) $tenant->getKey()
            && $tenant->status === 'active'
            && $user->accessibleTenants()->whereKey($tenant->getKey())->exists();
    }
}
