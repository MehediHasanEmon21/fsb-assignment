<?php

namespace App\Data;

use App\Models\Tenant;

final readonly class DashboardData
{
    /**
     * @param  array{total: int, active: int, inactive: int}  $users
     * @param  array{total: int, active: int, inactive: int}  $customers
     */
    public function __construct(
        public Tenant $tenant,
        public array $users,
        public array $customers,
        public EntitlementSnapshot $entitlements,
    ) {}
}
