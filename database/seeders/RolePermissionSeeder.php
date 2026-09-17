<?php

namespace Database\Seeders;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();
        $registrar->setPermissionsTeamId(null);

        foreach (PermissionName::cases() as $permission) {
            Permission::query()->firstOrCreate([
                'name' => $permission->value,
                'guard_name' => 'sanctum',
            ]);
        }

        foreach ($this->rolePermissions() as $roleName => $permissions) {
            $role = Role::query()->firstOrCreate([
                'tenant_id' => null,
                'name' => $roleName,
                'guard_name' => 'sanctum',
            ]);

            $role->syncPermissions($permissions);
        }

        $registrar->forgetCachedPermissions();
    }

    /**
     * @return array<string, list<string>>
     */
    private function rolePermissions(): array
    {
        $allPermissions = array_map(
            static fn (PermissionName $permission): string => $permission->value,
            PermissionName::cases(),
        );

        return [
            RoleName::SuperAdmin->value => [],
            RoleName::TenantAdmin->value => $allPermissions,
            RoleName::Manager->value => [
                PermissionName::TenantView->value,
                PermissionName::UsersView->value,
                PermissionName::UsersCreate->value,
                PermissionName::UsersUpdate->value,
                PermissionName::CustomersView->value,
                PermissionName::CustomersCreate->value,
                PermissionName::CustomersUpdate->value,
                PermissionName::SubscriptionView->value,
                PermissionName::DashboardView->value,
            ],
            RoleName::User->value => [
                PermissionName::TenantView->value,
                PermissionName::CustomersView->value,
                PermissionName::SubscriptionView->value,
                PermissionName::DashboardView->value,
            ],
        ];
    }
}
