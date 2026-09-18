<?php

namespace App\Services;

use App\Enums\RoleName;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;

class UserService
{
    private const FEATURE_KEY = 'users';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly EntitlementService $entitlements,
    ) {}

    /**
     * @param  array{name: string, email: string, password: string}  $attributes
     */
    public function create(User $actor, array $attributes): User
    {
        $tenant = $this->tenantContext->current();
        Gate::forUser($actor)->authorize('createMember', $tenant);

        return DB::transaction(function () use ($tenant, $attributes): User {
            $tenant = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $activeMembers = $tenant->users()->wherePivot('status', 'active')->count();
            $this->entitlements->synchronizeUsage($tenant, self::FEATURE_KEY, $activeMembers);
            $this->entitlements->consume($tenant, self::FEATURE_KEY);

            $user = User::query()->create([
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'password' => Hash::make($attributes['password']),
                'status' => 'active',
            ]);
            $user->tenants()->attach($tenant, ['status' => 'active']);
            $user->assignRole(RoleName::User->value);

            return $user;
        });
    }
}
