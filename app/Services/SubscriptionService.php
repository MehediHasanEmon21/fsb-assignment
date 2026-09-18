<?php

namespace App\Services;

use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use App\Exceptions\SubscriptionOperationException;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class SubscriptionService
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function current(User $user, int $tenantId): ?Subscription
    {
        $tenant = $this->currentTenant($tenantId);
        Gate::forUser($user)->authorize('viewSubscription', $tenant);

        return DB::transaction(function () use ($tenant): ?Subscription {
            $this->lockTenant($tenant);
            $now = CarbonImmutable::now();
            $this->expireEndedSubscriptions($tenant, $now);

            return $this->activeQuery($tenant, $now)
                ->with('plan.features')
                ->latest('starts_at')
                ->first();
        });
    }

    public function assign(User $user, int $tenantId, int $planId): Subscription
    {
        $tenant = $this->currentTenant($tenantId);
        Gate::forUser($user)->authorize('manageSubscription', $tenant);

        if ($tenant->status !== 'active') {
            throw new SubscriptionOperationException('An inactive tenant cannot start a subscription.');
        }

        return DB::transaction(function () use ($tenant, $planId): Subscription {
            $this->lockTenant($tenant);
            $plan = Plan::query()
                ->where('status', 'active')
                ->findOrFail($planId);
            $now = CarbonImmutable::now();
            $this->expireEndedSubscriptions($tenant, $now);

            $activeSubscriptions = $this->activeQuery($tenant, $now)
                ->lockForUpdate()
                ->get();

            if ($activeSubscriptions->count() === 1
                && $activeSubscriptions->first()->plan_id === $plan->id) {
                return $activeSubscriptions->first()->load('plan.features');
            }

            foreach ($activeSubscriptions as $subscription) {
                $subscription->update([
                    'status' => SubscriptionStatus::Cancelled,
                    'ends_at' => $now,
                    'cancelled_at' => $now,
                ]);
            }

            $subscription = Subscription::query()->create([
                'tenant_id' => $tenant->id,
                'plan_id' => $plan->id,
                'status' => SubscriptionStatus::Active,
                'starts_at' => $now,
                'ends_at' => BillingInterval::endingAtFor($plan->billing_interval, $now),
                'cancelled_at' => null,
            ]);

            return $subscription->load('plan.features');
        });
    }

    public function cancel(User $user, int $tenantId): Subscription
    {
        $tenant = $this->currentTenant($tenantId);
        Gate::forUser($user)->authorize('manageSubscription', $tenant);

        return DB::transaction(function () use ($tenant): Subscription {
            $this->lockTenant($tenant);
            $now = CarbonImmutable::now();
            $this->expireEndedSubscriptions($tenant, $now);

            $subscription = $this->activeQuery($tenant, $now)
                ->lockForUpdate()
                ->latest('starts_at')
                ->first();

            if ($subscription === null) {
                throw new SubscriptionOperationException('The tenant has no active subscription to cancel.');
            }

            $subscription->update([
                'status' => SubscriptionStatus::Cancelled,
                'ends_at' => $now,
                'cancelled_at' => $now,
            ]);

            return $subscription->refresh()->load('plan.features');
        });
    }

    private function currentTenant(int $tenantId): Tenant
    {
        if (! $this->tenantContext->has() || $this->tenantContext->id() !== $tenantId) {
            throw (new ModelNotFoundException)->setModel(Tenant::class, [$tenantId]);
        }

        return $this->tenantContext->current();
    }

    private function lockTenant(Tenant $tenant): void
    {
        Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
    }

    private function expireEndedSubscriptions(Tenant $tenant, CarbonImmutable $now): void
    {
        Subscription::query()
            ->forTenant($tenant)
            ->where('status', SubscriptionStatus::Active->value)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', $now)
            ->update(['status' => SubscriptionStatus::Expired->value]);
    }

    private function activeQuery(Tenant $tenant, CarbonImmutable $now): Builder
    {
        return Subscription::query()
            ->forTenant($tenant)
            ->where('status', SubscriptionStatus::Active->value)
            ->where('starts_at', '<=', $now)
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>', $now);
            });
    }
}
