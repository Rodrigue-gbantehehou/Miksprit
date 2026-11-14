<?php

declare(strict_types=1);

use App\Entity\Membership;
use App\Entity\Organization;
use App\Entity\OrganizationStatus;
use App\Entity\PaymentAttempt;
use App\Entity\TenantContext;
use App\Entity\TenantId;
use App\Entity\TenantRole;
use App\Entity\XofAmount;
use App\Repository\DoctrinePaymentAttemptRepository;
use App\Repository\DoctrineMembershipRepository;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$configuration = ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 2).'/src/Entity'], true);
$configuration->enableNativeLazyObjects(true);
$connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration);
$entityManager = new EntityManager($connection, $configuration);
$entities = [
    $entityManager->getClassMetadata(Organization::class),
    $entityManager->getClassMetadata(Membership::class),
    $entityManager->getClassMetadata(PaymentAttempt::class),
];
(new SchemaTool($entityManager))->createSchema($entities);

$activeTenant = new TenantId('tenant_active_01');
$inactiveTenant = new TenantId('tenant_paused_1');
$entityManager->persist(Organization::create($activeTenant, 'Active WiFi', 'active-wifi'));
$inactiveOrganization = Organization::create($inactiveTenant, 'Paused WiFi', 'paused-wifi');
$inactiveOrganization->suspend(TenantContext::fromMembership(Membership::owner('owner_paused_1', $inactiveTenant, 'owner_02'), 'owner_02'));
$entityManager->persist($inactiveOrganization);
$entityManager->persist(Membership::owner('member_active_1', $activeTenant, 'user_active_1'));
$entityManager->persist(Membership::owner('member_paused_1', $inactiveTenant, 'user_paused_1'));
$entityManager->flush();
$entityManager->clear();

$memberships = new DoctrineMembershipRepository($entityManager);
$activeMembership = $memberships->findForActorAndTenant('user_active_1', $activeTenant);
if ($activeMembership === null || $memberships->findForActorAndTenant('user_paused_1', $activeTenant) !== null
    || $memberships->findForActorAndTenant('user_paused_1', $inactiveTenant) !== null) {
    throw new RuntimeException('Membership query did not enforce actor, tenant, and active organization scope.');
}
$context = TenantContext::fromMembership($activeMembership, 'user_active_1');
$otherContext = TenantContext::fromMembership(Membership::owner('owner_other_01', $inactiveTenant, 'user_other_01'), 'user_other_01');

$attempts = new DoctrinePaymentAttemptRepository($entityManager);
$attempt = new PaymentAttempt('payment_active_1', $activeTenant, 'order_public_001', 'fedapay', new XofAmount(2500));
$otherTenantAttempt = new PaymentAttempt('payment_other_01', $inactiveTenant, 'order_public_002', 'fedapay', new XofAmount(3500));
$attempts->save($context, $attempt);
try {
    $attempts->save($context, $otherTenantAttempt);
    throw new RuntimeException('Repository accepted a payment attempt from another tenant.');
} catch (DomainException $exception) {
    if ($exception->getMessage() === 'Repository accepted a payment attempt from another tenant.') {
        throw $exception;
    }
}
$attempts->save($otherContext, $otherTenantAttempt);
$loaded = $attempts->findForTenant($context, 'payment_active_1');
if ($loaded === null || $attempts->findForTenant($context, 'payment_other_01') !== null) {
    throw new RuntimeException('Payment attempt query did not enforce tenant scope.');
}
$loaded->markPending('provider_tx_01');
$attempts->save($context, $loaded);
$entityManager->clear();
$reloaded = $attempts->findForTenant($context, 'payment_active_1');
if ($reloaded?->state()->value !== 'pending') {
    throw new RuntimeException('Managed payment attempt update was not flushed.');
}

echo "Doctrine tenant repository SQL proof passed.\n";
$entityManager->close();
