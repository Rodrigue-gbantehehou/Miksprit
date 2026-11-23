<?php

declare(strict_types=1);

use App\Entity\Membership;
use App\Entity\TenantContext;
use App\Entity\TenantId;
use App\Service\Router\HotspotProvisioningUnavailable;
use App\Service\Router\TenantRouterCredentialSettings;
use Doctrine\DBAL\DriverManager;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
$connection->executeStatement('CREATE TABLE tenant_router_credentials (tenant_id VARCHAR(80) NOT NULL, credential_reference VARCHAR(160) NOT NULL, encrypted_credentials TEXT NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (tenant_id, credential_reference))');
$connection->executeStatement('CREATE TABLE routers (id VARCHAR(80) PRIMARY KEY, tenant_id VARCHAR(80) NOT NULL, credential_reference VARCHAR(160) NOT NULL, status VARCHAR(20) NOT NULL)');
$key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
$settings = new TenantRouterCredentialSettings($connection, $key);
$tenantA = TenantContext::fromMembership(Membership::owner('membership_router_a', new TenantId('tenant_router_a'), 'router_owner_a'), 'router_owner_a');
$tenantB = TenantContext::fromMembership(Membership::owner('membership_router_b', new TenantId('tenant_router_b'), 'router_owner_b'), 'router_owner_b');
$referenceA = 'tenant_router_a:router-'.bin2hex(random_bytes(8));
$referenceB = 'tenant_router_b:router-'.bin2hex(random_bytes(8));
$settings->save($tenantA, $referenceA, 'api-owner-a', 'router-secret-a');
$settings->save($tenantB, $referenceB, 'api-owner-b', 'router-secret-b');
$ciphertext = (string) $connection->fetchOne('SELECT encrypted_credentials FROM tenant_router_credentials WHERE tenant_id = ? AND credential_reference = ?', [$tenantA->tenantId->value, $referenceA]);
if (str_contains($ciphertext, 'router-secret-a') || str_contains($ciphertext, 'api-owner-a')) {
    throw new RuntimeException('Router credentials were stored without encryption.');
}

if ($settings->resolve($tenantA->tenantId, $referenceA)->username() !== 'api-owner-a'
    || $settings->resolve($tenantB->tenantId, $referenceB)->password() !== 'router-secret-b') {
    throw new RuntimeException('Router credentials did not decrypt in their own tenant scope.');
}
try {
    $settings->resolve($tenantB->tenantId, $referenceA);
    throw new RuntimeException('A tenant resolved another tenant’s RouterOS credentials.');
} catch (HotspotProvisioningUnavailable) {
}

$settings->save($tenantA, $referenceA, 'api-owner-a-rotated', 'router-secret-a-rotated');
if ($settings->resolve($tenantA->tenantId, $referenceA)->password() !== 'router-secret-a-rotated') {
    throw new RuntimeException('Router credential rotation did not replace the encrypted value.');
}
$connection->insert('routers', ['id' => 'router_credential_a', 'tenant_id' => $tenantA->tenantId->value, 'credential_reference' => $referenceA, 'status' => 'active']);
try {
    $settings->revoke($tenantA, $referenceA);
    throw new RuntimeException('Active router credentials were revoked without suspension.');
} catch (DomainException) {
}
$connection->update('routers', ['status' => 'suspended'], ['id' => 'router_credential_a']);
$settings->revoke($tenantA, $referenceA);
try {
    $settings->resolve($tenantA->tenantId, $referenceA);
    throw new RuntimeException('Revoked RouterOS credentials remained resolvable.');
} catch (HotspotProvisioningUnavailable) {
}

echo "Tenant-scoped encrypted RouterOS credential storage, rotation, and revocation proof passed.\n";
