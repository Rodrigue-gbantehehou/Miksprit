<?php

declare(strict_types=1);

namespace App\Entity;

enum LedgerAccount: string
{
    case ExternalPaymentClearing = 'external_payment_clearing';
    case ProviderFeesExpense = 'provider_fees_expense';
    case PlatformCommissionMemo = 'platform_commission_memo';
    case TenantShareMemo = 'tenant_share_memo';
    case CustomerRefunds = 'customer_refunds';
    case Reconciliation = 'reconciliation';
}
