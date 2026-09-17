<?php

namespace App\Tenancy;

use App\Models\Tenant;
use LogicException;

class TenantContext
{
    private ?Tenant $tenant = null;

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function has(): bool
    {
        return $this->tenant !== null;
    }

    public function current(): Tenant
    {
        return $this->tenant ?? throw new LogicException('Tenant context has not been resolved.');
    }

    public function id(): int
    {
        return (int) $this->current()->getKey();
    }

    public function forget(): void
    {
        $this->tenant = null;
    }
}
