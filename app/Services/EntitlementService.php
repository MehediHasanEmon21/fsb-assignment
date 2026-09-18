<?php

namespace App\Services;

use App\Data\FeatureEntitlement;
use App\Enums\FeatureType;
use App\Enums\SubscriptionStatus;
use App\Exceptions\FeatureConfigurationException;
use App\Exceptions\FeatureUnavailableException;
use App\Exceptions\QuotaExceededException;
use App\Models\FeatureUsage;
use App\Models\PlanFeature;
use App\Models\Subscription;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class EntitlementService
{
    public function entitlement(Tenant $tenant, string $featureKey): ?FeatureEntitlement
    {
        return $this->resolve($tenant, $featureKey);
    }

    public function canUseFeature(Tenant $tenant, string $featureKey): bool
    {
        return $this->entitlement($tenant, $featureKey)?->canUse() ?? false;
    }

    public function remainingQuota(Tenant $tenant, string $featureKey): ?int
    {
        return $this->entitlement($tenant, $featureKey)?->remaining;
    }

    public function usage(Tenant $tenant, string $featureKey): int
    {
        return $this->entitlement($tenant, $featureKey)?->usage ?? 0;
    }

    public function consume(Tenant $tenant, string $featureKey, int $amount = 1): FeatureEntitlement
    {
        if ($amount < 1) {
            throw new InvalidArgumentException('The consumption amount must be at least one.');
        }

        return DB::transaction(function () use ($tenant, $featureKey, $amount): FeatureEntitlement {
            $lockedTenant = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $entitlement = $this->resolve($lockedTenant, $featureKey, true);

            if ($entitlement === null || ! $entitlement->enabled) {
                throw new FeatureUnavailableException("Feature [{$featureKey}] is not available for this tenant.");
            }

            if ($entitlement->type === FeatureType::Boolean) {
                return $entitlement;
            }

            if ($entitlement->remaining === null || $amount > $entitlement->remaining) {
                throw new QuotaExceededException("Feature [{$featureKey}] quota has been exceeded.");
            }

            $usage = FeatureUsage::query()->firstOrCreate(
                [
                    'tenant_id' => $lockedTenant->id,
                    'feature_id' => $entitlement->featureId,
                    'period_start' => $entitlement->periodStart,
                    'period_end' => $entitlement->periodEnd,
                ],
                ['usage' => 0],
            );

            $usage = FeatureUsage::query()->whereKey($usage->id)->lockForUpdate()->firstOrFail();

            if ($entitlement->limit === null || $usage->usage + $amount > $entitlement->limit) {
                throw new QuotaExceededException("Feature [{$featureKey}] quota has been exceeded.");
            }

            $usage->increment('usage', $amount);

            return $this->resolve($lockedTenant, $featureKey, true)
                ?? throw new FeatureUnavailableException("Feature [{$featureKey}] is not available for this tenant.");
        });
    }

    public function synchronizeUsage(
        Tenant $tenant,
        string $featureKey,
        int $usage,
    ): FeatureEntitlement {
        if ($usage < 0) {
            throw new InvalidArgumentException('Feature usage cannot be negative.');
        }

        return DB::transaction(function () use ($tenant, $featureKey, $usage): FeatureEntitlement {
            $lockedTenant = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $entitlement = $this->resolve($lockedTenant, $featureKey, true);

            if ($entitlement === null || ! $entitlement->enabled) {
                throw new FeatureUnavailableException("Feature [{$featureKey}] is not available for this tenant.");
            }

            if ($entitlement->type !== FeatureType::Limit) {
                throw new FeatureConfigurationException(
                    "Feature [{$featureKey}] does not support numerical usage.",
                );
            }

            FeatureUsage::query()->updateOrCreate(
                [
                    'tenant_id' => $lockedTenant->id,
                    'feature_id' => $entitlement->featureId,
                    'period_start' => $entitlement->periodStart,
                    'period_end' => $entitlement->periodEnd,
                ],
                ['usage' => $usage],
            );

            return $this->resolve($lockedTenant, $featureKey, true)
                ?? throw new FeatureUnavailableException("Feature [{$featureKey}] is not available for this tenant.");
        });
    }

    private function resolve(
        Tenant $tenant,
        string $featureKey,
        bool $lockForUpdate = false,
    ): ?FeatureEntitlement {
        if ($tenant->status !== 'active') {
            return null;
        }

        $now = CarbonImmutable::now();
        $subscriptionQuery = Subscription::query()
            ->forTenant($tenant)
            ->where('status', SubscriptionStatus::Active->value)
            ->whereNotNull('starts_at')
            ->whereNotNull('ends_at')
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>', $now)
            ->latest('starts_at');

        if ($lockForUpdate) {
            $subscriptionQuery->lockForUpdate();
        }

        $subscription = $subscriptionQuery->first();

        if ($subscription === null) {
            return null;
        }

        $planFeatureQuery = PlanFeature::query()
            ->with('feature')
            ->where('plan_id', $subscription->plan_id)
            ->whereHas('feature', fn (Builder $query): Builder => $query->where('key', $featureKey));

        if ($lockForUpdate) {
            $planFeatureQuery->lockForUpdate();
        }

        $planFeature = $planFeatureQuery->first();

        if ($planFeature === null) {
            return null;
        }

        $type = FeatureType::tryFrom($planFeature->feature->type)
            ?? throw new FeatureConfigurationException("Feature [{$featureKey}] has an unsupported type.");
        $periodStart = CarbonImmutable::instance($subscription->starts_at);
        $periodEnd = CarbonImmutable::instance($subscription->ends_at);

        if ($type === FeatureType::Boolean) {
            return new FeatureEntitlement(
                featureId: $planFeature->feature_id,
                key: $featureKey,
                type: $type,
                enabled: $this->booleanValue($planFeature->value, $featureKey),
                limit: null,
                usage: 0,
                remaining: null,
                periodStart: $periodStart,
                periodEnd: $periodEnd,
            );
        }

        $limit = $this->limitValue($planFeature->value, $featureKey);
        $usageQuery = FeatureUsage::query()
            ->forTenant($tenant)
            ->where('feature_id', $planFeature->feature_id)
            ->where('period_start', $periodStart)
            ->where('period_end', $periodEnd);

        if ($lockForUpdate) {
            $usageQuery->lockForUpdate();
        }

        $usage = (int) ($usageQuery->value('usage') ?? 0);

        return new FeatureEntitlement(
            featureId: $planFeature->feature_id,
            key: $featureKey,
            type: $type,
            enabled: true,
            limit: $limit,
            usage: $usage,
            remaining: max(0, $limit - $usage),
            periodStart: $periodStart,
            periodEnd: $periodEnd,
        );
    }

    private function booleanValue(string $value, string $featureKey): bool
    {
        return match (strtolower(trim($value))) {
            '1', 'true' => true,
            '0', 'false' => false,
            default => throw new FeatureConfigurationException(
                "Feature [{$featureKey}] must have a boolean value.",
            ),
        };
    }

    private function limitValue(string $value, string $featureKey): int
    {
        $value = trim($value);

        if ($value === '' || ! ctype_digit($value)) {
            throw new FeatureConfigurationException(
                "Feature [{$featureKey}] must have a non-negative integer limit.",
            );
        }

        return (int) $value;
    }
}
