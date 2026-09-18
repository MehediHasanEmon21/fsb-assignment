<?php

namespace App\Services;

use App\Enums\RoleName;
use App\Exceptions\FeatureConfigurationException;
use App\Exceptions\FeatureUnavailableException;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class TenantMembershipService
{
    private const FEATURE_KEY = 'users';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly EntitlementService $entitlements,
        private readonly RoleAssignmentService $roles,
        private readonly TenantCacheService $cache,
    ) {}

    /**
     * @param  array{search?: ?string, status?: ?string, sort?: ?string, direction?: ?string, per_page?: ?int}  $filters
     */
    public function paginate(User $actor, int $tenantId, array $filters): LengthAwarePaginator
    {
        $tenant = $this->currentTenant($tenantId);
        Gate::forUser($actor)->authorize('viewMembers', $tenant);

        $query = $tenant->users()
            ->select('users.*')
            ->with('roles:id,name');

        if ($search = $filters['search'] ?? null) {
            $query->where(function ($query) use ($search): void {
                $query->where('users.name', 'like', "%{$search}%")
                    ->orWhere('users.email', 'like', "%{$search}%");
            });
        }

        if ($status = $filters['status'] ?? null) {
            $query->wherePivot('status', $status);
        }

        $sort = $filters['sort'] ?? 'name';
        $direction = $filters['direction'] ?? 'asc';

        return $query
            ->orderBy("users.{$sort}", $direction)
            ->orderBy('users.id')
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();
    }

    public function updateStatus(
        User $actor,
        int $tenantId,
        int $memberId,
        string $status,
    ): User {
        $tenant = $this->currentTenant($tenantId);
        Gate::forUser($actor)->authorize('updateMember', $tenant);

        DB::transaction(function () use ($actor, $tenant, $memberId, $status): void {
            $tenant = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $member = $this->member($tenant, $memberId, true);
            $this->assertMemberCanBeManaged($actor, $member, $status);
            $currentStatus = $member->pivot->status;

            if ($currentStatus === $status) {
                return;
            }

            if ($status === 'active') {
                $this->synchronizeUsage($tenant);
                $this->entitlements->consume($tenant, self::FEATURE_KEY);
            }

            $tenant->users()->updateExistingPivot($memberId, ['status' => $status]);
            DB::afterCommit(fn () => $this->cache->invalidateDashboard($tenant->id));

            if ($status === 'inactive') {
                $this->synchronizeUsage($tenant, false);
            }
        });

        return $tenant->users()
            ->with('roles:id,name')
            ->whereKey($memberId)
            ->firstOrFail();
    }

    public function updateRole(
        User $actor,
        int $tenantId,
        int $memberId,
        RoleName $role,
    ): User {
        $tenant = $this->currentTenant($tenantId);

        DB::transaction(function () use ($actor, $tenant, $memberId, $role): void {
            $tenant = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $member = $this->member($tenant, $memberId, true);
            $this->roles->assign($actor, $member, $tenant, $role);
        });

        return $tenant->users()
            ->with('roles:id,name')
            ->whereKey($memberId)
            ->firstOrFail();
    }

    public function remove(User $actor, int $tenantId, int $memberId): void
    {
        $tenant = $this->currentTenant($tenantId);
        Gate::forUser($actor)->authorize('deleteMember', $tenant);

        DB::transaction(function () use ($actor, $tenant, $memberId): void {
            $tenant = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $member = $this->member($tenant, $memberId, true);
            $this->assertMemberCanBeManaged($actor, $member, 'inactive');
            $member->syncRoles([]);
            $tenant->users()->detach($memberId);
            $this->synchronizeUsage($tenant, false);
            DB::afterCommit(fn () => $this->cache->invalidateDashboard($tenant->id));
        });
    }

    private function synchronizeUsage(Tenant $tenant, bool $required = true): void
    {
        try {
            $this->entitlements->synchronizeUsage(
                $tenant,
                self::FEATURE_KEY,
                $tenant->users()->wherePivot('status', 'active')->count(),
            );
        } catch (FeatureConfigurationException|FeatureUnavailableException $exception) {
            if ($required) {
                throw $exception;
            }
        }
    }

    private function member(Tenant $tenant, int $memberId, bool $lockForUpdate = false): User
    {
        $query = $tenant->users()->whereKey($memberId);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $member = $query->first();

        if ($member === null) {
            throw (new ModelNotFoundException)->setModel(User::class, [$memberId]);
        }

        return $member;
    }

    private function assertMemberCanBeManaged(User $actor, User $member, string $status): void
    {
        if ($actor->isSuperAdmin()) {
            return;
        }

        if ($actor->is($member) && $status === 'inactive') {
            throw new AuthorizationException(
                'Users cannot deactivate or remove their own membership.',
            );
        }

        if ($member->hasRole(RoleName::TenantAdmin->value)) {
            throw new AuthorizationException(
                'Tenant administrator membership can only be managed by a platform administrator.',
            );
        }
    }

    private function currentTenant(int $tenantId): Tenant
    {
        if (! $this->tenantContext->has() || $this->tenantContext->id() !== $tenantId) {
            throw (new ModelNotFoundException)->setModel(Tenant::class, [$tenantId]);
        }

        return $this->tenantContext->current();
    }
}
