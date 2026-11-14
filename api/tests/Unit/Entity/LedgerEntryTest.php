<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\LedgerAccount;
use App\Entity\LedgerEntry;
use App\Entity\LedgerEntryType;
use App\Entity\TenantId;
use PHPUnit\Framework\TestCase;

final class LedgerEntryTest extends TestCase
{
    public function testPostingIsBalancedImmutableXofAndTenantScoped(): void
    {
        $tenantId = new TenantId('tenant_0001');
        $entry = new LedgerEntry(
            id: 'ledg_00000001',
            ownerTenantId: $tenantId,
            journalId: 'journal_payment_01',
            entryType: LedgerEntryType::PaymentCollected,
            debitAccount: LedgerAccount::ExternalPaymentClearing,
            creditAccount: LedgerAccount::Reconciliation,
            amountMinor: 2500,
            currency: 'XOF',
            sourceType: 'fedapay',
            sourceReference: 'transaction_123',
            idempotencyKey: 'fedapay:transaction_123:payment-collected',
            occurredAt: new \DateTimeImmutable('2026-11-14T12:00:00+00:00'),
            recordedAt: new \DateTimeImmutable('2026-11-14T12:00:01+00:00'),
        );

        self::assertTrue($entry->isBalanced());
        self::assertFalse($entry->isIndicative());
        self::assertTrue($tenantId->equals($entry->tenantId()));
        self::assertSame(2500, $entry->amountMinor);
        self::assertSame('XOF', $entry->currency);
    }

    public function testIndicativeRevenueRowsAreExplicitlyMarked(): void
    {
        $entry = new LedgerEntry(
            id: 'ledg_00000002',
            ownerTenantId: new TenantId('tenant_0001'),
            journalId: 'journal_payment_01',
            entryType: LedgerEntryType::TenantShareEstimate,
            debitAccount: LedgerAccount::ExternalPaymentClearing,
            creditAccount: LedgerAccount::TenantShareMemo,
            amountMinor: 2300,
            currency: 'XOF',
            sourceType: 'fedapay',
            sourceReference: 'transaction_123',
            idempotencyKey: 'fedapay:transaction_123:tenant-share-estimate',
            occurredAt: new \DateTimeImmutable('2026-11-14T12:00:00+00:00'),
            recordedAt: new \DateTimeImmutable('2026-11-14T12:00:01+00:00'),
        );

        self::assertTrue($entry->isIndicative());
        self::assertTrue($entry->isBalanced());
    }

    public function testRejectsNonXofOrUnbalancedPosting(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LedgerEntry(
            'ledg_00000003', new TenantId('tenant_0001'), 'journal_01', LedgerEntryType::PaymentCollected,
            LedgerAccount::ExternalPaymentClearing, LedgerAccount::ExternalPaymentClearing, 100, 'USD',
            'fedapay', 'transaction_123', 'fedapay:transaction_123', new \DateTimeImmutable(), new \DateTimeImmutable(),
        );
    }

    public function testRejectsDuplicateSourcePostingAtTheDatabaseConstraintLevel(): void
    {
        $reflection = new \ReflectionClass(LedgerEntry::class);
        $constraints = $reflection->getAttributes(\Doctrine\ORM\Mapping\UniqueConstraint::class);
        $uniqueNames = array_map(static fn ($attribute) => $attribute->newInstance()->name, $constraints);

        self::assertContains('uniq_ledger_tenant_idempotency', $uniqueNames);
        self::assertContains('uniq_ledger_source_posting', $uniqueNames);
    }
}
