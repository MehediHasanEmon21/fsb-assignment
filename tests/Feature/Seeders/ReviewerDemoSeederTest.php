<?php

namespace Tests\Feature\Seeders;

use App\Http\Middleware\ResolveTenant;
use App\Models\Customer;
use App\Models\FeatureUsage;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ReviewerDemoSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_reviewer_seed_data_is_created_and_idempotent(): void
    {
        $this->seed();
        $this->seed();

        $this->assertDatabaseHas('users', ['email' => 'superadmin@example.test']);
        $this->assertDatabaseHas('users', ['email' => 'admin@acme.test']);
        $this->assertDatabaseHas('users', ['email' => 'admin@northwind.test']);
        $this->assertDatabaseHas('tenants', ['slug' => 'acme-software', 'status' => 'active']);
        $this->assertDatabaseHas('tenants', ['slug' => 'northwind-labs', 'status' => 'active']);
        $this->assertDatabaseHas('tenants', ['slug' => 'suspended-demo', 'status' => 'inactive']);

        $this->assertSame(10, User::query()->count());
        $this->assertSame(3, Tenant::query()->count());
        $this->assertSame(12, Customer::query()->count());
        $this->assertSame(2, Subscription::query()->where('status', 'active')->count());
        $this->assertSame(4, FeatureUsage::query()->count());
    }

    public function test_seeded_reviewer_accounts_can_exercise_tenant_flows(): void
    {
        $this->seed();

        $superAdmin = User::query()->where('email', 'superadmin@example.test')->firstOrFail();
        $acmeAdmin = User::query()->where('email', 'admin@acme.test')->firstOrFail();
        $acme = Tenant::query()->where('slug', 'acme-software')->firstOrFail();
        $northwind = Tenant::query()->where('slug', 'northwind-labs')->firstOrFail();

        $this->assertNotEmpty($this->login('superadmin@example.test'));
        $acmeToken = $this->login('admin@acme.test');

        $this->assertTrue($superAdmin->isSuperAdmin());
        $this->assertFalse($acmeAdmin->isSuperAdmin());
        $this->assertSame(
            ['acme-software'],
            $acmeAdmin->accessibleTenants()->pluck('slug')->all(),
        );
        $this->flushHeaders()
            ->withToken($acmeToken)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'admin@acme.test');

        $this->tenantRequest($acmeToken, $acme)
            ->getJson("/api/v1/tenants/{$acme->id}/dashboard")
            ->assertOk()
            ->assertJsonPath('data.tenant.name', 'Acme Software Ltd')
            ->assertJsonPath('data.metrics.customers.total', 6)
            ->assertJsonPath('data.subscription.plan.slug', 'professional')
            ->assertJsonPath('data.features.0.key', 'users')
            ->assertJsonPath('data.features.0.usage', 3)
            ->assertJsonPath('data.features.1.key', 'customers')
            ->assertJsonPath('data.features.1.usage', 12);

        $this->tenantRequest($acmeToken, $acme)
            ->getJson("/api/v1/tenants/{$acme->id}/subscription")
            ->assertOk()
            ->assertJsonPath('data.plan.slug', 'professional');

        try {
            app(TenantResolver::class)->resolve($acmeAdmin, $northwind->id);
            $this->fail('Seeded Acme admin should not resolve the Northwind tenant.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        $this->tenantRequest($acmeToken, $acme)
            ->getJson("/api/v1/tenants/{$northwind->id}/customers")
            ->assertNotFound();
    }

    private function login(string $email): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'password',
        ])
            ->assertOk()
            ->json('data.token');
    }

    private function tenantRequest(string $token, Tenant $tenant): self
    {
        return $this->flushHeaders()
            ->withToken($token)
            ->withHeader(ResolveTenant::HEADER, (string) $tenant->id);
    }
}
