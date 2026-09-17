<?php

namespace Tests\Feature\Http\Middleware;

use App\Http\Middleware\ResolveTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ResolveTenantTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('tenant')->get('/_testing/tenant-context', function (TenantContext $context): array {
            return ['tenant_id' => $context->id()];
        });
    }

    public function test_returns_401_when_the_request_is_unauthenticated(): void
    {
        $tenant = Tenant::factory()->create();

        $response = $this->withHeader(ResolveTenant::HEADER, (string) $tenant->id)
            ->getJson('/_testing/tenant-context');

        $response->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_returns_400_when_the_tenant_header_is_missing(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/_testing/tenant-context');

        $response->assertBadRequest()
            ->assertJsonPath('message', 'A valid X-Tenant-ID header is required.');
    }

    public function test_returns_400_when_the_tenant_header_is_manipulated(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->withHeader(ResolveTenant::HEADER, '1 OR 1=1')
            ->getJson('/_testing/tenant-context');

        $response->assertBadRequest()
            ->assertJsonPath('message', 'A valid X-Tenant-ID header is required.');
    }

    public function test_returns_403_when_the_user_selects_another_tenant(): void
    {
        $user = User::factory()->create();
        $accessibleTenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $user->tenants()->attach($accessibleTenant, ['status' => 'active']);

        $response = $this->actingAs($user)
            ->withHeader(ResolveTenant::HEADER, (string) $otherTenant->id)
            ->getJson('/_testing/tenant-context');

        $response->assertForbidden()
            ->assertJsonPath('message', 'Tenant access denied.');
    }

    public function test_resolves_and_safely_switches_between_accessible_tenants(): void
    {
        $user = User::factory()->create();
        $tenants = Tenant::factory()->count(2)->create();
        $user->tenants()->attach($tenants, ['status' => 'active']);

        $firstResponse = $this->actingAs($user)
            ->withHeader(ResolveTenant::HEADER, (string) $tenants->first()->id)
            ->getJson('/_testing/tenant-context');
        $secondResponse = $this->actingAs($user)
            ->withHeader(ResolveTenant::HEADER, (string) $tenants->last()->id)
            ->getJson('/_testing/tenant-context');

        $firstResponse->assertExactJson(['tenant_id' => $tenants->first()->id]);
        $secondResponse->assertExactJson(['tenant_id' => $tenants->last()->id]);
        $this->assertFalse(app(TenantContext::class)->has());
    }

    public function test_returns_403_for_a_nonexistent_tenant_without_revealing_its_existence(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->withHeader(ResolveTenant::HEADER, '999999')
            ->getJson('/_testing/tenant-context');

        $response->assertForbidden()
            ->assertJsonPath('message', 'Tenant access denied.');
    }
}
