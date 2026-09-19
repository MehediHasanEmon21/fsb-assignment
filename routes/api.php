<?php

use App\Http\Controllers\Api\V1\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Api\V1\Auth\CurrentUserController;
use App\Http\Controllers\Api\V1\Auth\RegisteredUserController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\PlanController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\TenantController;
use App\Http\Controllers\Api\V1\TenantMembershipController;
use App\Models\Tenant;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/auth')->name('api.v1.auth.')->group(function () {
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:login')
        ->name('login');

    Route::middleware(['auth:sanctum', 'active.user', 'abilities:api'])->group(function () {
        Route::post('/register', [RegisteredUserController::class, 'store'])
            ->middleware(['tenant', 'tenant.permissions', 'permission:users.create'])
            ->name('register');
        Route::get('/me', CurrentUserController::class)->name('me');
        Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
            ->name('logout');
    });
});

Route::prefix('v1/plans')
    ->name('api.v1.plans.')
    ->middleware(['auth:sanctum', 'active.user', 'abilities:api'])
    ->group(function () {
        Route::get('/', [PlanController::class, 'index'])->name('index');
        Route::get('/{plan}', [PlanController::class, 'show'])
            ->whereNumber('plan')
            ->name('show');
    });

Route::prefix('v1/tenants')
    ->name('api.v1.tenants.')
    ->middleware(['auth:sanctum', 'active.user', 'abilities:api'])
    ->group(function () {
        Route::get('/', [TenantController::class, 'index'])->name('index');
        Route::post('/', [TenantController::class, 'store'])
            ->middleware('can:create,'.Tenant::class)
            ->name('store');

        Route::middleware(['tenant', 'tenant.permissions'])->group(function () {
            Route::get('/{tenant}', [TenantController::class, 'show'])
                ->middleware('permission:tenant.view')
                ->whereNumber('tenant')
                ->name('show');
            Route::patch('/{tenant}', [TenantController::class, 'update'])
                ->middleware('permission:tenant.update')
                ->whereNumber('tenant')
                ->name('update');
            Route::get('/{tenant}/members', [TenantMembershipController::class, 'index'])
                ->middleware('permission:users.view')
                ->whereNumber('tenant')
                ->name('members.index');
            Route::patch('/{tenant}/members/{member}', [TenantMembershipController::class, 'update'])
                ->middleware('permission:users.update')
                ->whereNumber(['tenant', 'member'])
                ->name('members.update');
            Route::patch('/{tenant}/members/{member}/role', [TenantMembershipController::class, 'updateRole'])
                ->middleware('permission:users.update')
                ->whereNumber(['tenant', 'member'])
                ->name('members.role.update');
            Route::delete('/{tenant}/members/{member}', [TenantMembershipController::class, 'destroy'])
                ->middleware('permission:users.delete')
                ->whereNumber(['tenant', 'member'])
                ->name('members.destroy');
            Route::get('/{tenant}/customers', [CustomerController::class, 'index'])
                ->middleware('permission:customers.view')
                ->whereNumber('tenant')
                ->name('customers.index');
            Route::post('/{tenant}/customers', [CustomerController::class, 'store'])
                ->middleware('permission:customers.create')
                ->whereNumber('tenant')
                ->name('customers.store');
            Route::get('/{tenant}/customers/{customer}', [CustomerController::class, 'show'])
                ->middleware('permission:customers.view')
                ->whereNumber(['tenant', 'customer'])
                ->name('customers.show');
            Route::patch('/{tenant}/customers/{customer}', [CustomerController::class, 'update'])
                ->middleware('permission:customers.update')
                ->whereNumber(['tenant', 'customer'])
                ->name('customers.update');
            Route::delete('/{tenant}/customers/{customer}', [CustomerController::class, 'destroy'])
                ->middleware('permission:customers.delete')
                ->whereNumber(['tenant', 'customer'])
                ->name('customers.destroy');
            Route::get('/{tenant}/dashboard', DashboardController::class)
                ->middleware('permission:dashboard.view')
                ->whereNumber('tenant')
                ->name('dashboard.show');
            Route::get('/{tenant}/subscription', [SubscriptionController::class, 'show'])
                ->middleware('permission:subscription.view')
                ->whereNumber('tenant')
                ->name('subscription.show');
            Route::put('/{tenant}/subscription', [SubscriptionController::class, 'update'])
                ->middleware('permission:subscription.manage')
                ->whereNumber('tenant')
                ->name('subscription.update');
            Route::delete('/{tenant}/subscription', [SubscriptionController::class, 'destroy'])
                ->middleware('permission:subscription.manage')
                ->whereNumber('tenant')
                ->name('subscription.destroy');
        });
    });

Route::fallback(fn () => response()->json([
    'success' => false,
    'message' => 'Requested resource not found.',
], 404));
