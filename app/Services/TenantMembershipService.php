<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;

class TenantMembershipService
{
    public function __construct(private readonly TenantContext $tenantContext) {}

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

        $member = $tenant->users()->whereKey($memberId)->first();

        if ($member === null) {
            throw (new ModelNotFoundException)->setModel(User::class, [$memberId]);
        }

        $tenant->users()->updateExistingPivot($memberId, ['status' => $status]);

        return $tenant->users()
            ->with('roles:id,name')
            ->whereKey($memberId)
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
