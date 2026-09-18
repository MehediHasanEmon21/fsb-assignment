<?php

namespace Tests\Feature\Jobs;

use App\Enums\RoleName;
use App\Jobs\SendTenantAdminWelcomeEmail;
use App\Mail\TenantAdminWelcome;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SendTenantAdminWelcomeEmailTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantContext::class)->forget();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        parent::tearDown();
    }

    public function test_tenant_creation_dispatches_a_unique_after_commit_welcome_job(): void
    {
        Queue::fake();
        [, $token] = $this->platformAdmin();

        $response = $this->withToken($token)->postJson('/api/v1/tenants', [
            'name' => 'Queued Company',
            'slug' => 'queued-company',
            'email' => 'admin@queued.test',
        ])->assertCreated();

        $tenant = Tenant::query()->findOrFail($response->json('data.id'));
        $tenantAdmin = User::query()->where('email', 'admin@queued.test')->firstOrFail();

        Queue::assertPushed(
            SendTenantAdminWelcomeEmail::class,
            function (SendTenantAdminWelcomeEmail $job) use ($tenant, $tenantAdmin): bool {
                $this->assertInstanceOf(ShouldQueueAfterCommit::class, $job);
                $this->assertInstanceOf(ShouldBeUnique::class, $job);
                $this->assertSame('notifications', $job->queue);
                $this->assertSame(3, $job->tries);
                $this->assertSame([10, 30], $job->backoff);
                $this->assertSame(30, $job->timeout);

                return $job->tenantId === $tenant->id
                    && $job->userId === $tenantAdmin->id
                    && $job->uniqueId() === "tenant:{$tenant->id}:user:{$tenantAdmin->id}:welcome";
            },
        );
    }

    public function test_job_validates_membership_sets_tenant_context_and_sends_the_email(): void
    {
        Mail::fake();
        [$tenant, $tenantAdmin] = $this->tenantAdmin();
        $context = app(TenantContext::class);

        (new SendTenantAdminWelcomeEmail($tenant->id, $tenantAdmin->id))->handle($context);

        Mail::assertSent(TenantAdminWelcome::class, function (TenantAdminWelcome $mail) use (
            $tenant,
            $tenantAdmin,
        ): bool {
            return $mail->hasTo($tenantAdmin->email)
                && $mail->tenantName === $tenant->name
                && $mail->adminName === $tenantAdmin->name
                && $mail->adminEmail === $tenantAdmin->email
                && $mail->temporaryPassword === 'password';
        });
        $this->assertFalse($context->has());
    }

    public function test_job_does_not_send_for_a_user_outside_the_tenant(): void
    {
        Mail::fake();
        [$tenant] = $this->tenantAdmin();
        $otherUser = User::factory()->create();
        $staleTenant = Tenant::factory()->create();
        $context = app(TenantContext::class);
        $context->set($staleTenant);

        (new SendTenantAdminWelcomeEmail($tenant->id, $otherUser->id))->handle($context);

        Mail::assertNothingSent();
        $this->assertFalse($context->has());
    }

    public function test_failed_job_is_reported_with_tenant_safe_context(): void
    {
        Log::spy();
        $exception = new RuntimeException('Mail transport unavailable.');
        $job = new SendTenantAdminWelcomeEmail(10, 20);

        $job->failed($exception);

        Log::shouldHaveReceived('error')
            ->once()
            ->with('Tenant welcome email job failed.', [
                'tenant_id' => 10,
                'user_id' => 20,
                'exception' => $exception,
            ]);
    }

    /** @return array{Tenant, User} */
    private function tenantAdmin(): array
    {
        $tenant = Tenant::factory()->create();
        $tenantAdmin = User::factory()->create();
        $tenant->users()->attach($tenantAdmin, ['status' => 'active']);

        return [$tenant, $tenantAdmin];
    }

    /** @return array{User, string} */
    private function platformAdmin(): array
    {
        $this->seed(RolePermissionSeeder::class);
        $superAdmin = User::factory()->create();
        $assignmentTenant = Tenant::factory()->create();
        app(PermissionRegistrar::class)->setPermissionsTeamId($assignmentTenant->id);
        $superAdmin->assignRole(RoleName::SuperAdmin->value);
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        return [$superAdmin, $superAdmin->createToken('test')->plainTextToken];
    }
}
