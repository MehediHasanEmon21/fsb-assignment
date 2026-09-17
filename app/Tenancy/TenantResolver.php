<?php

namespace App\Tenancy;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class TenantResolver
{
    /**
     * @throws AuthorizationException
     */
    public function resolve(User $user, int $tenantId): Tenant
    {
        if ($tenantId < 1) {
            throw new AuthorizationException('Tenant access denied.');
        }

        $tenant = $user->isSuperAdmin()
            ? Tenant::query()->where('status', 'active')->whereKey($tenantId)->first()
            : $user->accessibleTenants()->whereKey($tenantId)->first();

        if ($tenant === null) {
            throw new AuthorizationException('Tenant access denied.');
        }

        return $tenant;
    }
}
