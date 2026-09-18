<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\RoleName;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'status'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    protected string $guard_name = 'sanctum';

    public function isSuperAdmin(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        $roleTable = config('permission.table_names.roles');
        $modelRoleTable = config('permission.table_names.model_has_roles');

        return DB::table($modelRoleTable)
            ->join($roleTable, "{$roleTable}.id", '=', "{$modelRoleTable}.role_id")
            ->where("{$modelRoleTable}.model_type", $this->getMorphClass())
            ->where("{$modelRoleTable}.model_id", $this->getKey())
            ->where("{$roleTable}.name", RoleName::SuperAdmin->value)
            ->where("{$roleTable}.guard_name", $this->guard_name)
            ->exists();
    }

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class)
            ->withPivot(['id', 'status'])
            ->withTimestamps();
    }

    public function accessibleTenants(): BelongsToMany
    {
        return $this->tenants()
            ->wherePivot('status', 'active')
            ->where('tenants.status', 'active');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
