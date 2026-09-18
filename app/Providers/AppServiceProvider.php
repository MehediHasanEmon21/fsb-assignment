<?php

namespace App\Providers;

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use App\Policies\CustomerPolicy;
use App\Policies\TenantPolicy;
use App\Policies\UserPolicy;
use App\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Customer::class, CustomerPolicy::class);
        Gate::policy(Tenant::class, TenantPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::before(
            fn (User $user): ?bool => $user->isSuperAdmin() ? true : null,
        );

        RateLimiter::for('login', function (Request $request): Limit {
            $email = Str::transliterate(
                Str::lower($request->string('email')->trim()->toString()),
            );

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });
    }
}
