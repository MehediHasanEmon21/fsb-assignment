<?php

namespace App\Services;

use App\Data\DashboardData;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DashboardService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly EntitlementService $entitlements,
    ) {}

    public function get(User $actor, int $tenantId): DashboardData
    {
        $tenant = $this->currentTenant($tenantId);
        Gate::forUser($actor)->authorize('viewDashboard', $tenant);

        return new DashboardData(
            tenant: $tenant,
            users: $this->statusCounts('tenant_user', $tenant),
            customers: $this->statusCounts((new Customer)->getTable(), $tenant),
            entitlements: $this->entitlements->snapshot($tenant),
        );
    }

    /**
     * @return array{total: int, active: int, inactive: int}
     */
    private function statusCounts(string $table, Tenant $tenant): array
    {
        $counts = DB::table($table)
            ->where('tenant_id', $tenant->id)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'total' => (int) $counts->sum(),
            'active' => (int) ($counts->get('active') ?? 0),
            'inactive' => (int) ($counts->get('inactive') ?? 0),
        ];
    }

    private function currentTenant(int $tenantId): Tenant
    {
        if (! $this->tenantContext->has() || $this->tenantContext->id() !== $tenantId) {
            throw (new ModelNotFoundException)->setModel(Tenant::class, [$tenantId]);
        }

        return $this->tenantContext->current();
    }
}
