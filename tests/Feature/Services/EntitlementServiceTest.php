<?php

namespace Tests\Feature\Services;

use App\Enums\SubscriptionStatus;
use App\Exceptions\FeatureConfigurationException;
use App\Exceptions\FeatureUnavailableException;
use App\Exceptions\QuotaExceededException;
use App\Models\Feature;
use App\Models\FeatureUsage;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\EntitlementService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class EntitlementServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private EntitlementService $entitlements;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-18 12:00:00');
        $this->entitlements = app(EntitlementService::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_boolean_feature_can_be_enabled_or_disabled_by_plan_configuration(): void
    {
        [$enabledTenant] = $this->tenantWithFeature('analytics', 'boolean', 'true');
        [$disabledTenant] = $this->tenantWithFeature('exports', 'boolean', 'false');

        $this->assertTrue($this->entitlements->canUseFeature($enabledTenant, 'analytics'));
        $this->assertFalse($this->entitlements->canUseFeature($disabledTenant, 'exports'));
        $this->assertFalse($this->entitlements->canUseFeature($enabledTenant, 'missing'));
        $this->assertNull($this->entitlements->remainingQuota($enabledTenant, 'analytics'));
    }

    public function test_numerical_feature_reports_usage_and_remaining_quota(): void
    {
        [$tenant, , $feature, $subscription] = $this->tenantWithFeature('customers', 'limit', '10');
        FeatureUsage::factory()->create([
            'tenant_id' => $tenant->id,
            'feature_id' => $feature->id,
            'usage' => 4,
            'period_start' => $subscription->starts_at,
            'period_end' => $subscription->ends_at,
        ]);

        $entitlement = $this->entitlements->entitlement($tenant, 'customers');

        $this->assertNotNull($entitlement);
        $this->assertTrue($entitlement->canUse());
        $this->assertSame(10, $entitlement->limit);
        $this->assertSame(4, $this->entitlements->usage($tenant, 'customers'));
        $this->assertSame(6, $this->entitlements->remainingQuota($tenant, 'customers'));
    }

    public function test_consumption_updates_usage_and_enforces_the_exact_limit(): void
    {
        [$tenant, , $feature] = $this->tenantWithFeature('users', 'limit', '5');

        $afterFour = $this->entitlements->consume($tenant, 'users', 4);
        $this->assertSame(4, $afterFour->usage);
        $this->assertSame(1, $afterFour->remaining);

        $afterFive = app(EntitlementService::class)->consume($tenant, 'users');
        $this->assertSame(5, $afterFive->usage);
        $this->assertSame(0, $afterFive->remaining);
        $this->assertFalse($this->entitlements->canUseFeature($tenant, 'users'));

        try {
            app(EntitlementService::class)->consume($tenant, 'users');
            $this->fail('Expected quota consumption beyond the limit to fail.');
        } catch (QuotaExceededException) {
            $this->assertDatabaseHas('feature_usages', [
                'tenant_id' => $tenant->id,
                'feature_id' => $feature->id,
                'usage' => 5,
            ]);
        }
    }

    public function test_oversized_consumption_is_rolled_back_without_partial_usage(): void
    {
        [$tenant, , $feature] = $this->tenantWithFeature('customers', 'limit', '3');

        try {
            $this->entitlements->consume($tenant, 'customers', 4);
            $this->fail('Expected oversized consumption to fail.');
        } catch (QuotaExceededException) {
            $this->assertDatabaseMissing('feature_usages', [
                'tenant_id' => $tenant->id,
                'feature_id' => $feature->id,
            ]);
        }
    }

    public function test_feature_entitlements_and_usage_are_tenant_specific(): void
    {
        [$firstTenant, , $feature, $firstSubscription] = $this->tenantWithFeature(
            'customers',
            'limit',
            '2',
        );
        [$secondTenant, $secondPlan, , $secondSubscription] = $this->tenantWithFeature(
            'customers_other_plan',
            'limit',
            '20',
        );
        $secondPlan->features()->detach();
        $secondPlan->features()->attach($feature, ['value' => '20']);
        FeatureUsage::factory()->create([
            'tenant_id' => $firstTenant->id,
            'feature_id' => $feature->id,
            'usage' => 2,
            'period_start' => $firstSubscription->starts_at,
            'period_end' => $firstSubscription->ends_at,
        ]);
        FeatureUsage::factory()->create([
            'tenant_id' => $secondTenant->id,
            'feature_id' => $feature->id,
            'usage' => 3,
            'period_start' => $secondSubscription->starts_at,
            'period_end' => $secondSubscription->ends_at,
        ]);

        $this->assertSame(0, $this->entitlements->remainingQuota($firstTenant, 'customers'));
        $this->assertSame(17, $this->entitlements->remainingQuota($secondTenant, 'customers'));
        $this->assertFalse($this->entitlements->canUseFeature($firstTenant, 'customers'));
        $this->assertTrue($this->entitlements->canUseFeature($secondTenant, 'customers'));
    }

    public function test_boolean_consumption_checks_access_without_creating_usage(): void
    {
        [$tenant] = $this->tenantWithFeature('analytics', 'boolean', 'true');

        $entitlement = $this->entitlements->consume($tenant, 'analytics');

        $this->assertTrue($entitlement->canUse());
        $this->assertDatabaseCount('feature_usages', 0);
    }

    public function test_missing_disabled_or_inactive_entitlement_cannot_be_consumed(): void
    {
        [$tenant] = $this->tenantWithFeature('analytics', 'boolean', 'false');

        foreach (['analytics', 'missing'] as $featureKey) {
            try {
                $this->entitlements->consume($tenant, $featureKey);
                $this->fail("Expected [{$featureKey}] to be unavailable.");
            } catch (FeatureUnavailableException) {
                $this->assertDatabaseCount('feature_usages', 0);
            }
        }

        $tenant->update(['status' => 'inactive']);

        $this->assertFalse($this->entitlements->canUseFeature($tenant->fresh(), 'analytics'));
    }

    public function test_consumption_rechecks_tenant_status_from_the_locked_database_row(): void
    {
        [$staleTenant] = $this->tenantWithFeature('customers', 'limit', '10');
        Tenant::query()->whereKey($staleTenant->id)->update(['status' => 'inactive']);

        $this->expectException(FeatureUnavailableException::class);
        $this->entitlements->consume($staleTenant, 'customers');
    }

    public function test_expired_or_cancelled_subscription_grants_no_entitlement(): void
    {
        [$tenant, , , $subscription] = $this->tenantWithFeature('analytics', 'boolean', 'true');
        $subscription->update([
            'status' => SubscriptionStatus::Expired,
            'ends_at' => now()->subMinute(),
        ]);

        $this->assertFalse($this->entitlements->canUseFeature($tenant, 'analytics'));

        $subscription->update([
            'status' => SubscriptionStatus::Cancelled,
            'ends_at' => now()->addMonth(),
        ]);

        $this->assertFalse($this->entitlements->canUseFeature($tenant, 'analytics'));
    }

    public function test_invalid_configuration_and_consumption_amount_fail_closed(): void
    {
        [$tenant] = $this->tenantWithFeature('customers', 'limit', '-1');

        $this->expectException(FeatureConfigurationException::class);
        $this->entitlements->canUseFeature($tenant, 'customers');
    }

    public function test_consumption_amount_must_be_positive(): void
    {
        [$tenant] = $this->tenantWithFeature('customers', 'limit', '10');

        $this->expectException(InvalidArgumentException::class);
        $this->entitlements->consume($tenant, 'customers', 0);
    }

    /**
     * @return array{Tenant, Plan, Feature, Subscription}
     */
    private function tenantWithFeature(
        string $key,
        string $type,
        string $value,
    ): array {
        $tenant = Tenant::factory()->create();
        $plan = Plan::factory()->create();
        $feature = Feature::factory()->create([
            'name' => str($key)->headline()->toString(),
            'key' => $key,
            'type' => $type,
        ]);
        $plan->features()->attach($feature, ['value' => $value]);
        $subscription = Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => now()->startOfMonth(),
            'ends_at' => now()->startOfMonth()->addMonth(),
            'cancelled_at' => null,
        ]);

        return [$tenant, $plan, $feature, $subscription];
    }
}
