<?php

namespace App\Services;

use App\Enums\RoleName;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

class TenantService
{
    private const TEMPORARY_TENANT_ADMIN_PASSWORD = 'password';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PermissionRegistrar $permissions,
    ) {}

    /**
     * @param  array{name: string, slug: string, email: string}  $attributes
     */
    public function create(User $actor, array $attributes): Tenant
    {
        Gate::forUser($actor)->authorize('create', Tenant::class);

        $previousTenantId = $this->permissions->getPermissionsTeamId();
        $tenantAdmin = null;

        try {
            return DB::transaction(function () use ($attributes, &$tenantAdmin): Tenant {
                $tenantAdmin = User::query()->create([
                    'name' => $attributes['name'],
                    'email' => $attributes['email'],
                    'password' => Hash::make(self::TEMPORARY_TENANT_ADMIN_PASSWORD),
                    'status' => 'active',
                ]);

                $tenant = Tenant::query()->create([
                    ...$attributes,
                    'status' => 'active',
                ]);

                $tenantAdmin->tenants()->attach($tenant, ['status' => 'active']);
                $this->permissions->setPermissionsTeamId($tenant->id);
                $tenantAdmin->unsetRelation('roles');
                $tenantAdmin->assignRole(RoleName::TenantAdmin->value);

                return $tenant;
            });
        } finally {
            $this->permissions->setPermissionsTeamId($previousTenantId);
            $tenantAdmin?->unsetRelation('roles');
            $tenantAdmin?->unsetRelation('permissions');
        }
    }

    /**
     * @param  array{search?: ?string, status?: ?string, sort?: ?string, direction?: ?string, per_page?: ?int}  $filters
     */
    public function paginateAccessible(User $user, array $filters): LengthAwarePaginator
    {
        $query = $user->isSuperAdmin()
            ? Tenant::query()
            : $user->accessibleTenants()->select('tenants.*');

        if ($search = $filters['search'] ?? null) {
            $query->where(function ($query) use ($search): void {
                $query->where('tenants.name', 'like', "%{$search}%")
                    ->orWhere('tenants.slug', 'like', "%{$search}%")
                    ->orWhere('tenants.email', 'like', "%{$search}%");
            });
        }

        if ($status = $filters['status'] ?? null) {
            $query->where('tenants.status', $status);
        }

        $sort = $filters['sort'] ?? 'name';
        $direction = $filters['direction'] ?? 'asc';

        return $query
            ->orderBy("tenants.{$sort}", $direction)
            ->orderBy('tenants.id')
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();
    }

    public function view(User $user, int $tenantId): Tenant
    {
        $tenant = $this->currentTenant($tenantId);
        Gate::forUser($user)->authorize('view', $tenant);

        return $tenant;
    }

    /**
     * @param  array{name?: string, slug?: string, email?: ?string, status?: string}  $attributes
     */
    public function update(User $user, int $tenantId, array $attributes): Tenant
    {
        $tenant = $this->currentTenant($tenantId);
        Gate::forUser($user)->authorize('update', $tenant);

        if (array_key_exists('status', $attributes) && ! $user->isSuperAdmin()) {
            throw new AuthorizationException(
                'Only a platform administrator can change tenant status.',
            );
        }

        $tenant->update($attributes);

        return $tenant->refresh();
    }

    private function currentTenant(int $tenantId): Tenant
    {
        if (! $this->tenantContext->has() || $this->tenantContext->id() !== $tenantId) {
            throw (new ModelNotFoundException)->setModel(Tenant::class, [$tenantId]);
        }

        return $this->tenantContext->current();
    }
}
