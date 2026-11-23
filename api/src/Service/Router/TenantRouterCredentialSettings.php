<?php

declare(strict_types=1);

namespace App\Service\Router;

use App\Entity\TenantContext;
use App\Entity\TenantId;
use App\Entity\TenantPermission;
use App\Service\Provider\Router\RouterOsCredentials;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Tenant-owned RouterOS credentials encrypted at rest and resolved only for that tenant. */
#[AsAlias(RouterCredentialResolver::class)]
final readonly class TenantRouterCredentialSettings implements RouterCredentialResolver
{
    public function __construct(
        private Connection $connection,
        #[Autowire('%env(default:empty_secret:ROUTER_CREDENTIAL_ENCRYPTION_KEY)%')]
        private string $encryptionKey,
    ) {}

    public function save(TenantContext $tenant, string $reference, string $username, string $password): void
    {
        $this->assertPermission($tenant);
        if (!str_starts_with($reference, $tenant->tenantId->value.':') || strlen($reference) > 160) {
            throw new \InvalidArgumentException('La référence du secret routeur ne correspond pas à cet espace.');
        }
        $username = trim($username);
        if ($username === '' || strlen($username) > 190 || $password === '' || strlen($password) > 500
            || preg_match('/[\x00-\x1F\x7F]/', $username.$password) === 1) {
            throw new \InvalidArgumentException('Le nom d’utilisateur ou le mot de passe API du routeur est invalide.');
        }
        $key = $this->resolvedKey();
        if ($key === null) throw new \LogicException('ROUTER_CREDENTIAL_ENCRYPTION_KEY must be a stable 32-byte key or base64: value.');
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $payload = json_encode(['username' => $username, 'password' => $password], JSON_THROW_ON_ERROR);
        $encrypted = base64_encode($nonce.sodium_crypto_secretbox($payload, $nonce, $key));
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $sql = 'INSERT INTO tenant_router_credentials (tenant_id, credential_reference, encrypted_credentials, updated_at) VALUES (?, ?, ?, ?)';
        $sql .= $this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform
            ? ' ON DUPLICATE KEY UPDATE encrypted_credentials = VALUES(encrypted_credentials), updated_at = VALUES(updated_at)'
            : ' ON CONFLICT (tenant_id, credential_reference) DO UPDATE SET encrypted_credentials = excluded.encrypted_credentials, updated_at = excluded.updated_at';
        $this->connection->executeStatement($sql, [$tenant->tenantId->value, $reference, $encrypted, $now]);
    }

    public function resolve(TenantId $tenantId, string $credentialReference): RouterOsCredentials
    {
        if (!str_starts_with($credentialReference, $tenantId->value.':')) throw new HotspotProvisioningUnavailable();
        $row = $this->connection->fetchAssociative(
            'SELECT encrypted_credentials FROM tenant_router_credentials WHERE tenant_id = ? AND credential_reference = ?',
            [$tenantId->value, $credentialReference],
        );
        if ($row === false) throw new HotspotProvisioningUnavailable();
        $key = $this->resolvedKey();
        $payload = base64_decode((string) $row['encrypted_credentials'], true);
        if ($key === null || !is_string($payload) || strlen($payload) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) throw new HotspotProvisioningUnavailable();
        $plain = sodium_crypto_secretbox_open(substr($payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $key);
        if (!is_string($plain)) throw new HotspotProvisioningUnavailable();
        try {
            $credentials = json_decode($plain, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($credentials) || !is_string($credentials['username'] ?? null) || !is_string($credentials['password'] ?? null)) throw new \UnexpectedValueException();
            return new RouterOsCredentials($credentials['username'], $credentials['password']);
        } catch (\Throwable $exception) {
            throw new HotspotProvisioningUnavailable(previous: $exception);
        }
    }

    public function revoke(TenantContext $tenant, string $reference): void
    {
        $this->assertPermission($tenant);
        if ((int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM routers WHERE tenant_id = ? AND credential_reference = ? AND status = 'active'",
            [$tenant->tenantId->value, $reference],
        ) > 0) throw new \DomainException('Suspendez le routeur avant de révoquer ses identifiants.');
        $this->connection->delete('tenant_router_credentials', ['tenant_id' => $tenant->tenantId->value, 'credential_reference' => $reference]);
    }

    private function assertPermission(TenantContext $tenant): void
    {
        if (!$tenant->allows(TenantPermission::ManageRouters)) throw new \DomainException('Access denied for this tenant operation.');
    }

    private function resolvedKey(): ?string
    {
        if (str_starts_with($this->encryptionKey, 'base64:')) {
            $decoded = base64_decode(substr($this->encryptionKey, 7), true);
            return is_string($decoded) && strlen($decoded) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES ? $decoded : null;
        }
        return strlen($this->encryptionKey) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES ? $this->encryptionKey : null;
    }
}
