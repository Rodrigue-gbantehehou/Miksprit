<?php

declare(strict_types=1);

namespace App\Entity;

enum TenantRole: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Operator = 'operator';
    case Analyst = 'analyst';

    public function allows(TenantPermission $permission): bool
    {
        return in_array($permission, match ($this) {
            self::Owner => TenantPermission::cases(),
            self::Manager => [
                TenantPermission::ManageSites,
                TenantPermission::ManageRouters,
                TenantPermission::ManagePlans,
                TenantPermission::ReadSales,
                TenantPermission::ManageVouchers,
                TenantPermission::ManageSessions,
                TenantPermission::ReadReports,
            ],
            self::Operator => [
                TenantPermission::ReadSales,
                TenantPermission::ManageVouchers,
                TenantPermission::ManageSessions,
            ],
            self::Analyst => [
                TenantPermission::ReadSales,
                TenantPermission::ReadReports,
            ],
        }, true);
    }
}
