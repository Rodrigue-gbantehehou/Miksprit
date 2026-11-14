<?php

declare(strict_types=1);

namespace App\Service\Ledger;

use App\Entity\LedgerAccount;
use App\Entity\LedgerEntryType;

/** Explicit posting policy; estimates are memorandum entries and do not authorize transfers. */
final class LedgerPostingRules
{
    public function assertAllowed(LedgerEntryType $type, LedgerAccount $debit, LedgerAccount $credit): void
    {
        $allowed = match ($type) {
            LedgerEntryType::PaymentCollected => [LedgerAccount::ExternalPaymentClearing, LedgerAccount::Reconciliation],
            LedgerEntryType::ProviderFeeObserved => [LedgerAccount::ProviderFeesExpense, LedgerAccount::ExternalPaymentClearing],
            LedgerEntryType::PlatformCommissionEstimate => [LedgerAccount::ExternalPaymentClearing, LedgerAccount::PlatformCommissionMemo],
            LedgerEntryType::TenantShareEstimate => [LedgerAccount::ExternalPaymentClearing, LedgerAccount::TenantShareMemo],
            LedgerEntryType::RefundConfirmed => [LedgerAccount::CustomerRefunds, LedgerAccount::ExternalPaymentClearing],
            LedgerEntryType::ReconciliationAdjustment => null,
        };

        if ($type === LedgerEntryType::ReconciliationAdjustment) {
            $adjustmentAccounts = [LedgerAccount::ExternalPaymentClearing, LedgerAccount::Reconciliation];
            if (in_array($debit, $adjustmentAccounts, true) && in_array($credit, $adjustmentAccounts, true) && $debit !== $credit) {
                return;
            }
        } elseif ($allowed !== null && $debit === $allowed[0] && $credit === $allowed[1]) {
            return;
        }

        throw new \DomainException(sprintf(
            'Account pair %s → %s is not allowed for ledger type %s.',
            $debit->value,
            $credit->value,
            $type->value,
        ));
    }
}
