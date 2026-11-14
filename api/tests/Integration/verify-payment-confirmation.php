<?php

declare(strict_types=1);

use App\Entity\OrderState;
use App\Entity\PaymentAttempt;
use App\Entity\PaymentOutboxMessage;
use App\Entity\PaymentState;
use App\Entity\PlanSnapshot;
use App\Entity\TenantId;
use App\Entity\VerifiedPaymentStatus;
use App\Entity\WifiOrder;
use App\Entity\XofAmount;
use App\Repository\DoctrinePaymentConfirmationStore;
use App\Repository\DoctrinePaymentIdempotencyStore;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$configuration = ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 2).'/src/Entity'], true);
$configuration->enableNativeLazyObjects(true);
$connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration);
$entityManager = new EntityManager($connection, $configuration);
(new SchemaTool($entityManager))->createSchema([
    $entityManager->getClassMetadata(PaymentAttempt::class),
    $entityManager->getClassMetadata(WifiOrder::class),
    $entityManager->getClassMetadata(PaymentOutboxMessage::class),
]);
$connection->executeStatement('CREATE TABLE payment_webhook_inbox (idempotency_key VARCHAR(80) NOT NULL PRIMARY KEY, state VARCHAR(16) NOT NULL, claimed_at DATETIME NOT NULL, completed_at DATETIME DEFAULT NULL)');
$webhookKeys = new DoctrinePaymentIdempotencyStore($connection);
if (!$webhookKeys->claim('fedapay:event-one') || $webhookKeys->claim('fedapay:event-one')) {
    throw new RuntimeException('Webhook inbox did not enforce an atomic first claim.');
}
$webhookKeys->complete('fedapay:event-one');
if ($webhookKeys->claim('fedapay:event-one')) {
    throw new RuntimeException('Completed webhook inbox event was claimed again.');
}

$tenantId = new TenantId('tenant_pay_001');
$now = new DateTimeImmutable('2026-11-14T00:00:00+00:00');
$order = new WifiOrder('order_pay_001', 'public_pay_001', $tenantId, new PlanSnapshot(
    'plan_pay_001', 'site_pay_001', $tenantId, 1, 'Forfait test', new XofAmount(2500), 3600, null, 1,
), $now);
$attempt = new PaymentAttempt('attempt_pay_001', $tenantId, $order->publicReference, 'fedapay', new XofAmount(2500));
$attempt->markPending('provider_tx_001');
$entityManager->persist($order);
$entityManager->persist($attempt);
$entityManager->flush();
$entityManager->clear();

$store = new DoctrinePaymentConfirmationStore($entityManager);
$evidence = new VerifiedPaymentStatus('fedapay', 'provider_tx_001', 'public_pay_001', new XofAmount(2500), 'XOF', PaymentState::Succeeded, $now);
$store->apply($evidence);
$store->apply($evidence);
$entityManager->clear();

$storedOrder = $entityManager->find(WifiOrder::class, 'order_pay_001');
$storedAttempt = $entityManager->find(PaymentAttempt::class, 'attempt_pay_001');
$outboxCount = (int) $connection->fetchOne('SELECT COUNT(*) FROM payment_outbox');
if ($storedOrder?->state() !== OrderState::AccessPending || $storedAttempt?->state() !== PaymentState::Succeeded || $outboxCount !== 1) {
    throw new RuntimeException('Verified payment did not atomically unlock access and enqueue exactly one outbox message.');
}

$secondAttempt = new PaymentAttempt('attempt_pay_002', $tenantId, 'public_pay_001', 'fedapay', new XofAmount(2500));
$secondAttempt->markPending('provider_tx_002');
$entityManager->persist($secondAttempt);
$entityManager->flush();
$entityManager->clear();
try {
    $store->apply(new VerifiedPaymentStatus('fedapay', 'provider_tx_002', 'public_pay_001', new XofAmount(2500), 'XOF', PaymentState::Succeeded, $now));
    throw new RuntimeException('A second successful payment was accepted without reconciliation.');
} catch (DomainException $exception) {
    if ($exception->getMessage() === 'A second successful payment was accepted without reconciliation.') {
        throw $exception;
    }
}
$entityManager->clear();
if ($entityManager->find(PaymentAttempt::class, 'attempt_pay_002')?->state() !== PaymentState::Pending
    || (int) $connection->fetchOne('SELECT COUNT(*) FROM payment_outbox') !== 1) {
    throw new RuntimeException('Rejected second payment was not rolled back atomically.');
}

echo "Verified payment, access gate, and transactional outbox proof passed.\n";
$entityManager->close();
