<?php

declare(strict_types=1);

use App\Entity\Organization;
use App\Entity\PaymentAttempt;
use App\Entity\PaymentOutboxMessage;
use App\Entity\PaymentOutboxMessageState;
use App\Entity\PaymentOutboxMessageType;
use App\Entity\PaymentState;
use App\Entity\PlanSnapshot;
use App\Entity\Router;
use App\Entity\Site;
use App\Entity\TenantId;
use App\Entity\VerifiedPaymentStatus;
use App\Entity\WifiOrder;
use App\Entity\XofAmount;
use App\Repository\DoctrinePaidOrderProvisioningRepository;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$configuration = ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 2).'/src/Entity'], true);
$configuration->enableNativeLazyObjects(true);
$connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration);
$entityManager = new EntityManager($connection, $configuration);
(new SchemaTool($entityManager))->createSchema(array_map(
    $entityManager->getClassMetadata(...),
    [PaymentAttempt::class, WifiOrder::class, PaymentOutboxMessage::class, Router::class, Site::class, Organization::class],
));
$connection->executeStatement("ALTER TABLE organizations ADD COLUMN organization_status VARCHAR(16) NOT NULL DEFAULT 'active'");

$tenant = new TenantId('tenant_router_001');
$now = new DateTimeImmutable('2026-11-20T00:00:00+00:00');
$site = new Site('site_router_001', $tenant, 'Router selection site', 'router-selection', hash('sha256', 'router-selection-token'), $now);
$site->activate();
$organization = Organization::create($tenant, 'Router selection tenant', 'router-selection-tenant', $now);
$order = new WifiOrder('order_router_001', 'public_router_001', $tenant, new PlanSnapshot(
    'plan_router_001', $site->id, $tenant, 1, 'Test access', new XofAmount(1000), 3600, null, 1,
), $now);
$attempt = new PaymentAttempt('attempt_router_001', $tenant, $order->publicReference, 'fedapay', new XofAmount(1000));
$attempt->markPending('provider_router_001');
$attempt->applyVerifiedStatus(new VerifiedPaymentStatus(
    'fedapay', 'provider_router_001', $order->publicReference, new XofAmount(1000), 'XOF', PaymentState::Succeeded, $now,
));
$order->confirmPayment($attempt);
$order->markAccessPending();
$outbox = new PaymentOutboxMessage('outbox_router_001', $tenant, $order->publicReference,
    PaymentOutboxMessageType::ProvisionPaidOrder, 'paid-order:tenant_router_001:public_router_001', PaymentOutboxMessageState::Pending, $now);
$router = new Router('router_router_001', $tenant, $site->id, 'Primary router', 'router.example.test', 8729, 'tenant_router_001:router-primary');
$router->markReachable('7.16', $now);

foreach ([$organization, $site, $order, $attempt, $outbox, $router] as $entity) $entityManager->persist($entity);
$entityManager->flush();
$entityManager->clear();

$repository = new DoctrinePaidOrderProvisioningRepository($connection, $entityManager);
$storedOutbox = $entityManager->find(PaymentOutboxMessage::class, 'outbox_router_001');
$context = $repository->findContext($storedOutbox);
if ($context?->router->id !== 'router_router_001') {
    throw new RuntimeException('A site with exactly one active router did not resolve its provisioning context.');
}

$secondRouter = new Router('router_router_002', $tenant, $site->id, 'Second router', 'router-2.example.test', 8729, 'tenant_router_001:router-secondary');
$secondRouter->markReachable('7.16', $now);
$entityManager->persist($secondRouter);
$entityManager->flush();
$entityManager->clear();
$storedOutbox = $entityManager->find(PaymentOutboxMessage::class, 'outbox_router_001');
if ($repository->findContext($storedOutbox) !== null) {
    throw new RuntimeException('Provisioning selected an arbitrary router when multiple active routers existed.');
}

echo "Paid-order provisioning rejects ambiguous active router selection.\n";
$entityManager->close();
