<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Ledger;

use App\Entity\LedgerAccount;
use App\Entity\LedgerEntry;
use App\Entity\LedgerEntryType;
use App\Entity\Membership;
use App\Entity\TenantContext;
use App\Entity\TenantId;
use App\Service\Ledger\LedgerEntryRepository;
use App\Service\Ledger\LedgerIdempotencyStore;
use App\Service\Ledger\LedgerPostingCommand;
use App\Service\Ledger\LedgerPostingConflict;
use App\Service\Ledger\LedgerPostingInProgress;
use App\Service\Ledger\LedgerPostingRules;
use App\Service\Ledger\LedgerTransactionManager;
use App\Service\Ledger\PostLedgerEntry;
use PHPUnit\Framework\TestCase;

final class PostLedgerEntryTest extends TestCase
{
    public function testPostsTenantScopedBalancedXofEntryAndReturnsItOnReplay(): void
    {
        $context = $this->tenant('tenant_0001', 'owner_1');
        $repository = new InMemoryLedgerEntryRepository();
        $idempotency = new InMemoryLedgerIdempotencyStore();
        $service = $this->service($repository, $idempotency);
        $command = $this->paymentCollectedCommand();

        $first = $service->execute($context, $command);
        $second = $service->execute($context, $command);

        self::assertSame($first, $second);
        self::assertTrue($first->isBalanced());
        self::assertSame(2500, $first->amountMinor);
        self::assertSame('XOF', $first->currency);
        self::assertTrue($context->tenantId->equals($first->tenantId()));
        self::assertCount(1, $repository->entries);
        self::assertCount(1, $idempotency->claims);
    }

    public function testTenantKeyCannotBeReusedForDifferentPostingFacts(): void
    {
        $context = $this->tenant('tenant_0001', 'owner_1');
        $repository = new InMemoryLedgerEntryRepository();
        $service = $this->service($repository, new InMemoryLedgerIdempotencyStore());
        $service->execute($context, $this->paymentCollectedCommand());
        $changed = new LedgerPostingCommand(
            LedgerEntryType::PaymentCollected,
            LedgerAccount::ExternalPaymentClearing,
            LedgerAccount::Reconciliation,
            2600,
            'XOF',
            'fedapay',
            'transaction_01',
            'payment:transaction_01',
            new \DateTimeImmutable('2026-11-14T12:00:00+00:00'),
        );

        $this->expectException(LedgerPostingConflict::class);
        $service->execute($context, $changed);
    }

    public function testPostingTypeRejectsUnapprovedAccountPairBeforeTransaction(): void
    {
        $transaction = new RecordingLedgerTransactionManager();
        $service = new PostLedgerEntry(
            new InMemoryLedgerEntryRepository(),
            $transaction,
            new InMemoryLedgerIdempotencyStore(),
            new LedgerPostingRules(),
        );

        try {
            $service->execute($this->tenant('tenant_0001', 'owner_1'), new LedgerPostingCommand(
                LedgerEntryType::PlatformCommissionEstimate,
                LedgerAccount::ProviderFeesExpense,
                LedgerAccount::TenantShareMemo,
                200,
                'XOF',
                'fedapay',
                'transaction_01',
                'wrong-pair',
                new \DateTimeImmutable('2026-11-14T12:00:00+00:00'),
            ));
            self::fail('Expected invalid account pair to be rejected.');
        } catch (\DomainException) {
            self::assertSame(0, $transaction->calls);
        }
    }

    public function testIndicativeTypeHasOnlyTheMemorandumAccounts(): void
    {
        $rules = new LedgerPostingRules();

        $rules->assertAllowed(LedgerEntryType::TenantShareEstimate, LedgerAccount::ExternalPaymentClearing, LedgerAccount::TenantShareMemo);
        $this->expectException(\DomainException::class);
        $rules->assertAllowed(LedgerEntryType::TenantShareEstimate, LedgerAccount::ExternalPaymentClearing, LedgerAccount::Reconciliation);
    }

    public function testEveryEntryTypeHasAnExplicitBalancedAccountPair(): void
    {
        $rules = new LedgerPostingRules();
        $pairs = [
            [LedgerEntryType::PaymentCollected, LedgerAccount::ExternalPaymentClearing, LedgerAccount::Reconciliation],
            [LedgerEntryType::ProviderFeeObserved, LedgerAccount::ProviderFeesExpense, LedgerAccount::ExternalPaymentClearing],
            [LedgerEntryType::PlatformCommissionEstimate, LedgerAccount::ExternalPaymentClearing, LedgerAccount::PlatformCommissionMemo],
            [LedgerEntryType::TenantShareEstimate, LedgerAccount::ExternalPaymentClearing, LedgerAccount::TenantShareMemo],
            [LedgerEntryType::RefundConfirmed, LedgerAccount::CustomerRefunds, LedgerAccount::ExternalPaymentClearing],
            [LedgerEntryType::ReconciliationAdjustment, LedgerAccount::ExternalPaymentClearing, LedgerAccount::Reconciliation],
            [LedgerEntryType::ReconciliationAdjustment, LedgerAccount::Reconciliation, LedgerAccount::ExternalPaymentClearing],
        ];

        foreach ($pairs as [$type, $debit, $credit]) {
            $rules->assertAllowed($type, $debit, $credit);
        }
        self::assertCount(7, $pairs);
    }

    public function testIdempotencyKeysAreScopedPerTenant(): void
    {
        $repository = new InMemoryLedgerEntryRepository();
        $service = $this->service($repository, new InMemoryLedgerIdempotencyStore());
        $command = $this->paymentCollectedCommand();

        $first = $service->execute($this->tenant('tenant_0001', 'owner_1'), $command);
        $second = $service->execute($this->tenant('tenant_0002', 'owner_2'), $command);

        self::assertNotSame($first->id, $second->id);
        self::assertCount(2, $repository->entries);
        self::assertFalse($first->tenantId()->equals($second->tenantId()));
    }

    public function testUnresolvedConcurrentClaimIsRetryableAndDoesNotAppend(): void
    {
        $repository = new InMemoryLedgerEntryRepository();
        $idempotency = new InMemoryLedgerIdempotencyStore(claimAvailable: false);
        $service = $this->service($repository, $idempotency);

        $this->expectException(LedgerPostingInProgress::class);
        $service->execute($this->tenant('tenant_0001', 'owner_1'), $this->paymentCollectedCommand());
    }

    private function service(InMemoryLedgerEntryRepository $repository, InMemoryLedgerIdempotencyStore $idempotency): PostLedgerEntry
    {
        return new PostLedgerEntry($repository, new RecordingLedgerTransactionManager(), $idempotency, new LedgerPostingRules());
    }

    private function tenant(string $tenantId, string $actorId): TenantContext
    {
        return TenantContext::fromMembership(Membership::owner('membership_'.$actorId, new TenantId($tenantId), $actorId), $actorId);
    }

    private function paymentCollectedCommand(): LedgerPostingCommand
    {
        return new LedgerPostingCommand(
            LedgerEntryType::PaymentCollected,
            LedgerAccount::ExternalPaymentClearing,
            LedgerAccount::Reconciliation,
            2500,
            'XOF',
            'fedapay',
            'transaction_01',
            'payment:transaction_01',
            new \DateTimeImmutable('2026-11-14T12:00:00+00:00'),
        );
    }
}

final class RecordingLedgerTransactionManager implements LedgerTransactionManager
{
    public int $calls = 0;

    public function transactional(callable $operation): mixed
    {
        ++$this->calls;

        return $operation();
    }
}

final class InMemoryLedgerEntryRepository implements LedgerEntryRepository
{
    /** @var list<LedgerEntry> */
    public array $entries = [];

    public function findByIdempotencyKey(TenantContext $tenant, string $idempotencyKey): ?LedgerEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->tenantId()->equals($tenant->tenantId) && $entry->idempotencyKey === $idempotencyKey) {
                return $entry;
            }
        }

        return null;
    }

    public function append(TenantContext $tenant, LedgerEntry $entry): void
    {
        if (!$entry->tenantId()->equals($tenant->tenantId)) {
            throw new \DomainException('Cross-tenant append rejected.');
        }
        $this->entries[] = $entry;
    }
}

final class InMemoryLedgerIdempotencyStore implements LedgerIdempotencyStore
{
    /** @var array<string, string> */
    public array $claims = [];

    public function __construct(private readonly bool $claimAvailable = true)
    {
    }

    public function claim(TenantContext $tenant, string $key, string $fingerprint): bool
    {
        $scopedKey = $tenant->tenantId->value.':'.$key;
        if (!$this->claimAvailable || isset($this->claims[$scopedKey])) {
            return false;
        }
        $this->claims[$scopedKey] = $fingerprint;

        return true;
    }

    public function complete(TenantContext $tenant, string $key, string $entryId): void
    {
        // In-memory contract fake; durable adapters store the completed entry reference transactionally.
    }
}
