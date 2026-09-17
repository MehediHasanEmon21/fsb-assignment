<?php

namespace App\Enums;

enum PermissionName: string
{
    case TenantView = 'tenant.view';
    case TenantUpdate = 'tenant.update';
    case UsersView = 'users.view';
    case UsersCreate = 'users.create';
    case UsersUpdate = 'users.update';
    case UsersDelete = 'users.delete';
    case CustomersView = 'customers.view';
    case CustomersCreate = 'customers.create';
    case CustomersUpdate = 'customers.update';
    case CustomersDelete = 'customers.delete';
    case SubscriptionView = 'subscription.view';
    case SubscriptionManage = 'subscription.manage';
    case DashboardView = 'dashboard.view';
}
