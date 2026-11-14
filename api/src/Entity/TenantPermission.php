<?php

declare(strict_types=1);

namespace App\Entity;

enum TenantPermission: string
{
    case ManageOrganization = 'organization.manage';
    case ManagePayouts = 'payouts.manage';
    case ManageMembers = 'members.manage';
    case ManageSites = 'sites.manage';
    case ManageRouters = 'routers.manage';
    case ManagePlans = 'plans.manage';
    case ReadSales = 'sales.read';
    case ManageVouchers = 'vouchers.manage';
    case ManageSessions = 'sessions.manage';
    case ReadReports = 'reports.read';
}
