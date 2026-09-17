<?php

namespace App\Services;

use App\Enums\RoleName;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

class RoleAssignmentService
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * @throws AuthorizationException
     */
    public function assign(User $actor, User $target, Tenant $tenant, RoleName $role): void
    {
        if (! $this->tenantContext->has() || $this->tenantContext->id() !== (int) $tenant->getKey()) {
            throw new AuthorizationException('The active tenant does not match the requested tenant.');
        }

        if (! $target->accessibleTenants()->whereKey($tenant->getKey())->exists()) {
            throw new AuthorizationException('The target user is not an active member of this tenant.');
        }

        Gate::forUser($actor)->authorize('assignTenantRole', $target);

        if ($role === RoleName::SuperAdmin) {
            throw new AuthorizationException('Platform roles cannot be assigned through tenant administration.');
        }

        if ($actor->hasRole(RoleName::TenantAdmin->value) && $role === RoleName::TenantAdmin) {
            throw new AuthorizationException('Tenant administrators cannot assign an equal or higher role.');
        }

        $target->syncRoles([$role->value]);
    }
}
