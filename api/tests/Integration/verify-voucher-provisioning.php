<?php

declare(strict_types=1);

use App\Entity\Membership;
use App\Entity\OrderState;
use App\Entity\PaymentAttempt;
use App\Entity\PaymentState;
use App\Entity\PlanSnapshot;
use App\Entity\Router;
use App\Entity\Site;
use App\Entity\SiteStatus;
use App\Entity\TenantContext;
use App\Entity\TenantId;
use App\Entity\TenantRole;
use App\Entity\VerifiedPaymentStatus;
use App\Entity\Voucher;
use App\Entity\VoucherStatus;
use App\Entity\WifiOrder;
use App\Entity\XofAmount;
use App\Repository\DoctrineVoucherProvisioningIdempotencyStore;
use App\Repository\DoctrineVoucherProvisioningRepository;
use App\Service\Provider\Router\SodiumVoucherCredentialCipher;
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
    $entityManager->getClassMetadata(Router::class),
    $entityManager->getClassMetadata(Site::class),
    $entityManager->getClassMetadata(Voucher::class),
    $entityManager->getClassMetadata(WifiOrder::class),
]);
$connection->executeStatement('CREATE TABLE voucher_provisioning_idempotency (idempotency_key VARCHAR(200) NOT NULL PRIMARY KEY, state VARCHAR(16) NOT NULL, claimed_at DATETIME NOT NULL, completed_at DATETIME DEFAULT NULL)');

$tenant = new TenantId('tenant_owner_01');
$otherTenant = new TenantId('tenant_other_01');
$context = TenantContext::fromMembership(Membership::owner('owner_membership_1', $tenant, 'user_owner_01'), 'user_owner_01');
$otherContext = TenantContext::fromMembership(Membership::owner('other_membership_1', $otherTenant, 'user_other_01'), 'user_other_01');
$now = new DateTimeImmutable();
$cipherKey = base64_encode(random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES));
$cipher = new SodiumVoucherCredentialCipher($cipherKey);

$snapshot = new PlanSnapshot('plan_000001', 'site_000001', $tenant, 1, 'Wi-Fi one hour', new XofAmount(2500), 3600, null, 1);
$order = new WifiOrder('order_000001', 'public_order_000001', $tenant, $snapshot, $now);
$attempt = new PaymentAttempt('payment_000001', $tenant, $order->publicReference, 'fedapay', new XofAmount(2500));
$attempt->markPending('provider_transaction_001');
$attempt->applyVerifiedStatus(new VerifiedPaymentStatus(
    'fedapay', 'provider_transaction_001', $order->publicReference, new XofAmount(2500), 'XOF', PaymentState::Succeeded, $now,
));
$order->confirmPayment($attempt);
$order->markAccessPending();
$voucher = new Voucher('voucher_000001', $tenant, $snapshot->siteId, str_repeat('a', 64), $order->publicReference);
$router = new Router('router_000001', $tenant, $snapshot->siteId, 'Router A', 'router.example.test', 8729, 'vault:router-a');
$router->markReachable('7.16', $now);
$site = new Site('site_000001', $tenant, 'Active site', 'active-site', hash('sha256', 'active-site-token'));
$site->activate();
$inactiveSite = new Site('site_000002', $tenant, 'Inactive site', 'inactive-site', hash('sha256', 'inactive-site-token'));
$inactiveSite->activate();
$inactiveSite->suspend();
$foreignSite = new Site('site_000003', $otherTenant, 'Foreign site', 'foreign-site', hash('sha256', 'foreign-site-token'));
$foreignSite->activate();
$entityManager->persist($order);
$entityManager->persist($voucher);
$entityManager->persist($router);
$entityManager->persist($site);
$entityManager->persist($inactiveSite);
$entityManager->persist($foreignSite);
$entityManager->flush();
$entityManager->clear();

$repository = new DoctrineVoucherProvisioningRepository($entityManager);
$router = $repository->findRouterForTenant($context, 'router_000001');
$voucher = $repository->findVoucherForTenant($context, 'voucher_000001');
$order = $repository->findOrderForTenantByPublicReference($context, 'public_order_000001');
$activeSite = $repository->findSiteForTenant($context, 'site_000001');
$pausedSite = $repository->findSiteForTenant($context, 'site_000002');
if ($router === null || $voucher === null || $order === null
    || $activeSite?->status() !== SiteStatus::Active
    || $pausedSite?->status() !== SiteStatus::Suspended
    || $repository->findSiteForTenant($context, 'site_missing_01') !== null
    || $repository->findSiteForTenant($context, 'site_000003') !== null
    || $repository->findRouterForTenant($context, 'router_other_01') !== null
    || $repository->findVoucherForTenant($context, 'voucher_other_01') !== null
    || $repository->findOrderForTenantByPublicReference($context, 'public_order_other_01') !== null) {
    throw new RuntimeException('Provisioning repository did not enforce tenant scope.');
}
if ($repository->findRouterForTenant($otherContext, 'router_000001') !== null
    || $repository->findSiteForTenant($otherContext, 'site_000001') !== null
    || $repository->findVoucherForTenant($otherContext, 'voucher_000001') !== null
    || $repository->findOrderForTenantByPublicReference($otherContext, 'public_order_000001') !== null) {
    throw new RuntimeException('Cross-tenant provisioning lookup returned a resource.');
}

$credentialEnvelope = $cipher->encrypt($tenant, 'voucher_000001', 'voucher-user', 'private-password');
$voucher->markProvisioned($credentialEnvelope, $now->modify('+1 hour'), 'w123456789012345');
$order->fulfill($voucher->id);
$repository->completeProvisioning($voucher, $order);
$entityManager->clear();
$completedVoucher = $repository->findVoucherForTenant($context, 'voucher_000001');
$completedOrder = $repository->findOrderForTenantByPublicReference($context, 'public_order_000001');
if ($completedVoucher?->status() !== VoucherStatus::Active || $completedOrder?->state() !== OrderState::Fulfilled) {
    throw new RuntimeException('Voucher and order were not atomically persisted as fulfilled.');
}
$storedCredential = $connection->fetchOne('SELECT encrypted_credential FROM vouchers WHERE id = ?', ['voucher_000001']);
if (!is_string($storedCredential) || $storedCredential === 'private-password'
    || $cipher->decrypt($tenant, 'voucher_000001', $storedCredential) !== ['username' => 'voucher-user', 'password' => 'private-password']) {
    throw new RuntimeException('Voucher credential was not persisted as authenticated ciphertext.');
}
try {
    $cipher->decrypt($otherTenant, 'voucher_000001', $storedCredential);
    throw new RuntimeException('Ciphertext copied to another tenant was accepted.');
} catch (RuntimeException $exception) {
    if ($exception->getMessage() === 'Ciphertext copied to another tenant was accepted.') {
        throw $exception;
    }
}
try {
    $cipher->decrypt($tenant, 'voucher_other_01', $storedCredential);
    throw new RuntimeException('Ciphertext copied to another voucher was accepted.');
} catch (RuntimeException $exception) {
    if ($exception->getMessage() === 'Ciphertext copied to another voucher was accepted.') {
        throw $exception;
    }
}

$idempotency = new DoctrineVoucherProvisioningIdempotencyStore($connection);
if (!$idempotency->claim('voucher-provisioning:tenant_owner_01:voucher_000001')
    || $idempotency->claim('voucher-provisioning:tenant_owner_01:voucher_000001')) {
    throw new RuntimeException('Idempotency claim was not unique.');
}
$idempotency->complete('voucher-provisioning:tenant_owner_01:voucher_000001');
$idempotency->complete('voucher-provisioning:tenant_owner_01:voucher_000001');
if ($idempotency->claim('voucher-provisioning:tenant_owner_01:voucher_000001')) {
    throw new RuntimeException('Completed idempotency claim was re-opened.');
}
if (!$idempotency->claim('voucher-provisioning:tenant_owner_01:retry') ) {
    throw new RuntimeException('Fresh idempotency claim failed.');
}
$idempotency->release('voucher-provisioning:tenant_owner_01:retry');
if (!$idempotency->claim('voucher-provisioning:tenant_owner_01:retry')) {
    throw new RuntimeException('Released confirmed-failure claim was not reusable.');
}

$envelope = $cipher->encrypt($tenant, 'voucher_000001', 'voucher-user', 'private-password');
$credentials = $cipher->decrypt($tenant, 'voucher_000001', $envelope);
if ($credentials !== ['username' => 'voucher-user', 'password' => 'private-password']
    || str_contains($envelope, 'private-password')) {
    throw new RuntimeException('Voucher credential encryption round trip failed.');
}
try {
    $tamperIndex = strlen('v1.') + 5;
    $tampered = substr($envelope, 0, $tamperIndex).($envelope[$tamperIndex] === 'A' ? 'B' : 'A').substr($envelope, $tamperIndex + 1);
    $cipher->decrypt($tenant, 'voucher_000001', $tampered);
    throw new RuntimeException('Tampered ciphertext passed authentication.');
} catch (RuntimeException $exception) {
    if ($exception->getMessage() === 'Tampered ciphertext passed authentication.') {
        throw $exception;
    }
}

echo "Voucher provisioning repositories and authenticated encryption proof passed.\n";
$entityManager->close();
