<?php

namespace App\Jobs;

use App\Mail\TenantAdminWelcome;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendTenantAdminWelcomeEmail implements ShouldBeUnique, ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public int $uniqueFor = 3600;

    /** @var list<int> */
    public array $backoff = [10, 30];

    public function __construct(
        public readonly int $tenantId,
        public readonly int $userId,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(TenantContext $tenantContext): void
    {
        $tenantContext->forget();
        $tenant = Tenant::query()
            ->whereKey($this->tenantId)
            ->where('status', 'active')
            ->first();

        if ($tenant === null) {
            Log::warning('Tenant welcome email skipped because the tenant is unavailable.', [
                'tenant_id' => $this->tenantId,
                'user_id' => $this->userId,
            ]);

            return;
        }

        $tenantAdmin = $tenant->users()
            ->whereKey($this->userId)
            ->where('users.status', 'active')
            ->wherePivot('status', 'active')
            ->first();

        if ($tenantAdmin === null) {
            Log::warning('Tenant welcome email skipped because the administrator is unavailable.', [
                'tenant_id' => $this->tenantId,
                'user_id' => $this->userId,
            ]);

            return;
        }

        $tenantContext->set($tenant);

        try {
            Mail::to($tenantAdmin->email)->send(new TenantAdminWelcome(
                tenantName: $tenant->name,
                adminName: $tenantAdmin->name,
                adminEmail: $tenantAdmin->email,
                temporaryPassword: (string) config('tenancy.temporary_admin_password'),
            ));
        } finally {
            $tenantContext->forget();
        }
    }

    public function uniqueId(): string
    {
        return "tenant:{$this->tenantId}:user:{$this->userId}:welcome";
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Tenant welcome email job failed.', [
            'tenant_id' => $this->tenantId,
            'user_id' => $this->userId,
            'exception' => $exception,
        ]);
    }
}
