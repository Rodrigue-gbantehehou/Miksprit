<?php

declare(strict_types=1);

namespace App\Entity;

enum LedgerEntryType: string
{
    case PaymentCollected = 'payment_collected';
    case ProviderFeeObserved = 'provider_fee_observed';
    case PlatformCommissionEstimate = 'platform_commission_estimate';
    case TenantShareEstimate = 'tenant_share_estimate';
    case RefundConfirmed = 'refund_confirmed';
    case ReconciliationAdjustment = 'reconciliation_adjustment';

    public function isIndicative(): bool
    {
        return in_array($this, [self::PlatformCommissionEstimate, self::TenantShareEstimate], true);
    }
}
