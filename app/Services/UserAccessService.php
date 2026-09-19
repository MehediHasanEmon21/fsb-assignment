<?php

namespace App\Services;

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UserAccessService
{
    /**
     * @return array{
     *     is_super_admin: bool,
     *     tenant_id: ?int,
     *     role: ?string,
     *     tenants: list<array{id: int, name: string, slug: string, role: ?string}>
     * }
     */
    public function summary(User $user): array
    {
        $tenants = $user->accessibleTenants()
            ->select(['tenants.id', 'tenants.name', 'tenants.slug'])
            ->orderBy('tenants.name')
            ->get();

        $tenantIds = $tenants->modelKeys();
        $rolesByTenant = $this->rolesByTenant($user, $tenantIds);
        $tenantAccess = $tenants->map(fn ($tenant): array => [
            'id' => (int) $tenant->getKey(),
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'role' => $rolesByTenant[(int) $tenant->getKey()] ?? null,
        ])->values()->all();

        $isSuperAdmin = $user->isSuperAdmin();
        $singleTenant = ! $isSuperAdmin && count($tenantAccess) === 1
            ? $tenantAccess[0]
            : null;

        return [
            'is_super_admin' => $isSuperAdmin,
            'tenant_id' => $singleTenant['id'] ?? null,
            'role' => $isSuperAdmin
                ? RoleName::SuperAdmin->value
                : ($singleTenant['role'] ?? null),
            'tenants' => $tenantAccess,
        ];
    }

    /**
     * @param  list<int>  $tenantIds
     * @return array<int, string>
     */
    private function rolesByTenant(User $user, array $tenantIds): array
    {
        if ($tenantIds === []) {
            return [];
        }

        $roleTable = config('permission.table_names.roles');
        $modelRoleTable = config('permission.table_names.model_has_roles');
        $rolePivotKey = config('permission.column_names.role_pivot_key') ?? 'role_id';
        $modelKey = config('permission.column_names.model_morph_key', 'model_id');
        $tenantKey = config('permission.column_names.team_foreign_key', 'tenant_id');

        return DB::table($modelRoleTable)
            ->join($roleTable, "{$roleTable}.id", '=', "{$modelRoleTable}.{$rolePivotKey}")
            ->where("{$modelRoleTable}.model_type", $user->getMorphClass())
            ->where("{$modelRoleTable}.{$modelKey}", $user->getKey())
            ->whereIn("{$modelRoleTable}.{$tenantKey}", $tenantIds)
            ->orderBy("{$roleTable}.id")
            ->pluck("{$roleTable}.name", "{$modelRoleTable}.{$tenantKey}")
            ->mapWithKeys(fn (string $role, int|string $tenantId): array => [
                (int) $tenantId => $role,
            ])
            ->all();
    }
}
