<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Feature;
use App\Models\FeatureUsage;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

class ReviewerDemoSeeder extends Seeder
{
    private const PASSWORD = 'password';

    public function __construct(private readonly PermissionRegistrar $permissions) {}

    public function run(): void
    {
        DB::transaction(function (): void {
            $acme = $this->tenant([
                'name' => 'Acme Software Ltd',
                'slug' => 'acme-software',
                'email' => 'admin@acme.test',
            ]);

            $northwind = $this->tenant([
                'name' => 'Northwind Labs',
                'slug' => 'northwind-labs',
                'email' => 'admin@northwind.test',
            ]);

            $suspended = $this->tenant([
                'name' => 'Suspended Demo Co',
                'slug' => 'suspended-demo',
                'email' => 'admin@suspended.test',
                'status' => 'inactive',
            ]);

            $this->seedPlatformAdmin($acme);

            $this->seedTenant(
                tenant: $acme,
                planSlug: 'professional',
                adminEmail: 'admin@acme.test',
                managerEmail: 'manager@acme.test',
                userEmail: 'user@acme.test',
                customerPrefix: 'acme',
                userUsage: 3,
                customerUsage: 12,
            );

            $this->seedTenant(
                tenant: $northwind,
                planSlug: 'starter',
                adminEmail: 'admin@northwind.test',
                managerEmail: 'manager@northwind.test',
                userEmail: 'user@northwind.test',
                customerPrefix: 'northwind',
                userUsage: 2,
                customerUsage: 5,
            );

            $inactiveAdmin = $this->user('Suspended Demo Admin', 'admin@suspended.test');
            $this->attachMember($suspended, $inactiveAdmin, RoleName::TenantAdmin, 'active');

            $this->permissions->setPermissionsTeamId(null);
        });
    }

    /**
     * @param  array{name: string, slug: string, email: string, status?: string}  $attributes
     */
    private function tenant(array $attributes): Tenant
    {
        return Tenant::query()->updateOrCreate(
            ['slug' => $attributes['slug']],
            [
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'status' => $attributes['status'] ?? 'active',
            ],
        );
    }

    private function seedPlatformAdmin(Tenant $assignmentTenant): User
    {
        $user = $this->user('Platform Super Admin', 'superadmin@example.test');

        $this->permissions->setPermissionsTeamId($assignmentTenant->id);
        $user->syncRoles([RoleName::SuperAdmin->value]);
        $this->permissions->setPermissionsTeamId(null);

        return $user;
    }

    private function seedTenant(
        Tenant $tenant,
        string $planSlug,
        string $adminEmail,
        string $managerEmail,
        string $userEmail,
        string $customerPrefix,
        int $userUsage,
        int $customerUsage,
    ): void {
        $admin = $this->user($tenant->name.' Admin', $adminEmail);
        $manager = $this->user($tenant->name.' Manager', $managerEmail);
        $user = $this->user($tenant->name.' User', $userEmail);
        $inactive = $this->user($tenant->name.' Inactive Member', "inactive@{$customerPrefix}.test");

        $this->attachMember($tenant, $admin, RoleName::TenantAdmin, 'active');
        $this->attachMember($tenant, $manager, RoleName::Manager, 'active');
        $this->attachMember($tenant, $user, RoleName::User, 'active');
        $this->attachMember($tenant, $inactive, RoleName::User, 'inactive');

        $this->seedSubscriptionAndUsage($tenant, $planSlug, $userUsage, $customerUsage);
        $this->seedCustomers($tenant, $customerPrefix);
    }

    private function user(string $name, string $email): User
    {
        return User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make(self::PASSWORD),
                'status' => 'active',
            ],
        );
    }

    private function attachMember(Tenant $tenant, User $user, RoleName $role, string $status): void
    {
        $tenant->users()->syncWithoutDetaching([
            $user->id => ['status' => $status],
        ]);

        $tenant->users()->updateExistingPivot($user->id, ['status' => $status]);

        $this->permissions->setPermissionsTeamId($tenant->id);
        $user->syncRoles([$role->value]);
        $user->unsetRelation('roles');
        $this->permissions->setPermissionsTeamId(null);
    }

    private function seedSubscriptionAndUsage(
        Tenant $tenant,
        string $planSlug,
        int $userUsage,
        int $customerUsage,
    ): void {
        $plan = Plan::query()->where('slug', $planSlug)->firstOrFail();
        $startsAt = CarbonImmutable::create(2026, 9, 1, 0, 0, 0, 'UTC');
        $endsAt = $startsAt->addMonth();

        $subscription = Subscription::query()->updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'status' => SubscriptionStatus::Active->value,
            ],
            [
                'plan_id' => $plan->id,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'cancelled_at' => null,
            ],
        );

        $features = Feature::query()->whereIn('key', ['users', 'customers'])->get()->keyBy('key');

        foreach (['users' => $userUsage, 'customers' => $customerUsage] as $key => $usage) {
            FeatureUsage::query()->updateOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'feature_id' => $features->get($key)->id,
                    'period_start' => $subscription->starts_at,
                    'period_end' => $subscription->ends_at,
                ],
                ['usage' => $usage],
            );
        }
    }

    private function seedCustomers(Tenant $tenant, string $prefix): void
    {
        $customers = [
            ['name' => 'Avery Stone', 'email' => "avery@{$prefix}.test", 'phone' => '+1-555-0101', 'status' => 'active'],
            ['name' => 'Blake Carter', 'email' => "blake@{$prefix}.test", 'phone' => '+1-555-0102', 'status' => 'active'],
            ['name' => 'Casey Morgan', 'email' => "casey@{$prefix}.test", 'phone' => '+1-555-0103', 'status' => 'inactive'],
            ['name' => 'Dana Reed', 'email' => "dana@{$prefix}.test", 'phone' => '+1-555-0104', 'status' => 'active'],
            ['name' => 'Elliot Hayes', 'email' => "elliot@{$prefix}.test", 'phone' => '+1-555-0105', 'status' => 'active'],
            ['name' => 'Finley Brooks', 'email' => "finley@{$prefix}.test", 'phone' => '+1-555-0106', 'status' => 'active'],
        ];

        foreach ($customers as $customer) {
            Customer::query()->updateOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'email' => $customer['email'],
                ],
                $customer,
            );
        }
    }
}
