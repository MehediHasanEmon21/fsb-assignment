<?php

namespace App\Enums;

enum RoleName: string
{
    case SuperAdmin = 'super admin';
    case TenantAdmin = 'tenant admin';
    case Manager = 'manager';
    case User = 'user';
}
