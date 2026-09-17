<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

class SetTenantPermissionContext
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PermissionRegistrar $permissions,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $previousTenantId = $this->permissions->getPermissionsTeamId();
        $user = $request->user();

        $this->permissions->setPermissionsTeamId($this->tenantContext->id());
        $this->forgetPermissionRelations($user);

        try {
            return $next($request);
        } finally {
            $this->permissions->setPermissionsTeamId($previousTenantId);
            $this->forgetPermissionRelations($user);
        }
    }

    private function forgetPermissionRelations(mixed $user): void
    {
        if ($user instanceof User) {
            $user->unsetRelation('roles');
            $user->unsetRelation('permissions');
        }
    }
}
