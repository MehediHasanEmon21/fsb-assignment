<?php

namespace App\Data;

final readonly class DashboardData
{
    /**
     * @param  array{id: int, name: string, status: string}  $tenant
     * @param  array{total: int, active: int, inactive: int}  $users
     * @param  array{total: int, active: int, inactive: int}  $customers
     */
    public function __construct(
        public array $tenant,
        public array $users,
        public array $customers,
        public EntitlementSnapshot $entitlements,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            tenant: $data['tenant'],
            users: $data['users'],
            customers: $data['customers'],
            entitlements: EntitlementSnapshot::fromArray($data['entitlements']),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'tenant' => $this->tenant,
            'users' => $this->users,
            'customers' => $this->customers,
            'entitlements' => $this->entitlements->toArray(),
        ];
    }
}
