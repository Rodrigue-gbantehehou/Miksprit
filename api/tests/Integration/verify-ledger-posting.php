<?php

declare(strict_types=1);

use App\Entity\LedgerAccount;
use App\Entity\LedgerEntry;
use App\Entity\LedgerEntryType;
use App\Entity\Membership;
use App\Entity\TenantContext;
use App\Entity\TenantId;
use App\Repository\DoctrineLedgerEntryRepository;
use App\Repository\DoctrineLedgerIdempotencyStore;
use App\Repository\DoctrineLedgerTransactionManager;
use App\Service\Ledger\LedgerPostingCommand;
use App\Service\Ledger\LedgerPostingRules;
use App\Service\Ledger\PostLedgerEntry;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$configuration = ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 2).'/src/Entity'], true);
$configuration->enableNativeLazyObjects(true);
$connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration);
$entityManager = new EntityManager($connection, $configuration);
(new SchemaTool($entityManager))->createSchema([$entityManager->getClassMetadata(LedgerEntry::class)]);
$connection->executeStatement('CREATE TABLE ledger_posting_idempotency (tenant_id VARCHAR(80) NOT NULL, idempotency_key VARCHAR(200) NOT NULL, fingerprint VARCHAR(64) NOT NULL, state VARCHAR(16) NOT NULL, entry_id VARCHAR(80) DEFAULT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (tenant_id, idempotency_key))');

$tenantId = new TenantId('tenant_ledger_01');
$context = TenantContext::fromMembership(Membership::owner('ledger_owner_01', $tenantId, 'ledger_user_01'), 'ledger_user_01');
$service = new PostLedgerEntry(
    new DoctrineLedgerEntryRepository($entityManager),
    new DoctrineLedgerTransactionManager($connection),
    new DoctrineLedgerIdempotencyStore($connection),
    new LedgerPostingRules(),
);
$command = new LedgerPostingCommand(
    LedgerEntryType::PaymentCollected,
    LedgerAccount::ExternalPaymentClearing,
    LedgerAccount::Reconciliation,
    2500,
    'XOF',
    'verified_payment',
    'provider_tx_ledger_001',
    'ledger-idem-001',
    new DateTimeImmutable('2026-11-14T00:00:00+00:00'),
);
$first = $service->execute($context, $command);
$replay = $service->execute($context, $command);
if ($first->id !== $replay->id || (int) $connection->fetchOne('SELECT COUNT(*) FROM ledger_entries') !== 1) {
    throw new RuntimeException('Ledger idempotent replay created more than one posting.');
}
try {
    $service->execute($context, new LedgerPostingCommand(
        LedgerEntryType::PaymentCollected,
        LedgerAccount::ExternalPaymentClearing,
        LedgerAccount::Reconciliation,
        2501,
        'XOF',
        'verified_payment',
        'provider_tx_ledger_001',
        'ledger-idem-001',
        new DateTimeImmutable('2026-11-14T00:00:00+00:00'),
    ));
    throw new RuntimeException('Ledger accepted conflicting facts for a reused key.');
} catch (\App\Service\Ledger\LedgerPostingConflict $exception) {
    if ($exception->getMessage() === 'Ledger accepted conflicting facts for a reused key.') {
        throw $exception;
    }
}
if ((int) $connection->fetchOne('SELECT COUNT(*) FROM ledger_entries') !== 1) {
    throw new RuntimeException('Conflicting ledger replay changed the journal.');
}

echo "Tenant-scoped append-only ledger posting proof passed.\n";
$entityManager->close();
