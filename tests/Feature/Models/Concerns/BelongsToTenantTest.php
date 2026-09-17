<?php

namespace Tests\Feature\Models\Concerns;

use App\Models\Customer;
use App\Models\Feature;
use App\Models\FeatureUsage;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class BelongsToTenantTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_returns_only_records_owned_by_the_selected_tenant(): void
    {
        $selectedTenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $selectedCustomer = Customer::factory()->for($selectedTenant)->create();
        Customer::factory()->for($otherTenant)->create();

        $customerIds = Customer::query()->forTenant($selectedTenant)->pluck('id')->all();

        $this->assertSame([$selectedCustomer->id], $customerIds);
    }

    public function test_does_not_return_a_resource_id_owned_by_another_tenant(): void
    {
        $selectedTenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $otherCustomer = Customer::factory()->for($otherTenant)->create();

        $customer = Customer::query()
            ->forTenant($selectedTenant->id)
            ->find($otherCustomer->id);

        $this->assertNull($customer);
    }

    public function test_scopes_each_tenant_owned_model_explicitly(): void
    {
        $selectedTenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $plan = Plan::factory()->create();
        $feature = Feature::factory()->create();
        $selectedSubscription = Subscription::factory()->for($selectedTenant)->for($plan)->create();
        Subscription::factory()->for($otherTenant)->for($plan)->create();
        $selectedUsage = FeatureUsage::factory()->for($selectedTenant)->for($feature)->create();
        FeatureUsage::factory()->for($otherTenant)->for($feature)->create();

        $subscriptionIds = Subscription::query()->forTenant($selectedTenant)->pluck('id')->all();
        $usageIds = FeatureUsage::query()->forTenant($selectedTenant)->pluck('id')->all();

        $this->assertSame([$selectedSubscription->id], $subscriptionIds);
        $this->assertSame([$selectedUsage->id], $usageIds);
    }
}
