<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\Cache;

class TenantCacheService
{
    /** @param Closure(): array<string, mixed> $resolver */
    public function rememberDashboard(int $tenantId, Closure $resolver): array
    {
        return Cache::remember(
            $this->dashboardKey($tenantId),
            max(1, (int) config('tenant_cache.dashboard_ttl_seconds')),
            $resolver,
        );
    }

    /** @param Closure(): array<string, mixed> $resolver */
    public function rememberEntitlements(int $tenantId, Closure $resolver): array
    {
        return Cache::remember(
            $this->entitlementsKey($tenantId),
            max(1, (int) config('tenant_cache.entitlements_ttl_seconds')),
            $resolver,
        );
    }

    public function invalidateDashboard(int $tenantId): void
    {
        Cache::forget($this->dashboardKey($tenantId));
    }

    public function invalidateEntitlements(int $tenantId): void
    {
        Cache::forget($this->entitlementsKey($tenantId));
        $this->invalidateDashboard($tenantId);
    }

    public function dashboardKey(int $tenantId): string
    {
        return "tenant:{$tenantId}:dashboard:v1";
    }

    public function entitlementsKey(int $tenantId): string
    {
        return "tenant:{$tenantId}:features:v1";
    }
}
