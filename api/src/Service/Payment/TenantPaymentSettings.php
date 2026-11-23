<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Entity\TenantContext;
use App\Entity\TenantPermission;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Tenant-scoped payment credentials. Secrets are encrypted before reaching persistent storage. */
final readonly class TenantPaymentSettings
{
    public function __construct(
        private Connection $connection,
        #[Autowire('%env(default:empty_secret:TENANT_PAYMENT_ENCRYPTION_KEY)%')]
        private string $encryptionKey,
    ) {
    }

    /** @return array{provider: ?string, environment: ?string, configured: bool, lastApiCheckAt: ?string, updatedAt: ?string} */
    public function read(TenantContext $tenant): array
    {
        $this->assertPermission($tenant);
        $row = $this->connection->fetchAssociative(
            'SELECT provider, environment, last_api_check_at, updated_at FROM tenant_payment_settings WHERE tenant_id = ?',
            [$tenant->tenantId->value],
        );

        return [
            'provider' => $row === false ? null : (string) $row['provider'],
            'environment' => $row === false ? null : (string) $row['environment'],
            'configured' => $row !== false,
            'lastApiCheckAt' => $row === false ? null : (string) $row['last_api_check_at'],
            'updatedAt' => $row === false ? null : (string) $row['updated_at'],
        ];
    }

    /** @return array{provider: string, environment: string, configured: true, lastApiCheckAt: string, updatedAt: string} */
    public function save(TenantContext $tenant, string $provider, string $environment, string $apiKey, string $webhookSecret): array
    {
        $this->assertPermission($tenant);
        if ($provider !== 'fedapay') {
            throw new \InvalidArgumentException('Ce fournisseur de paiement n’est pas disponible.');
        }
        if (!in_array($environment, ['test', 'live'], true)) throw new \InvalidArgumentException('Choisissez un environnement test ou production.');
        $encryptionKey = $this->resolvedEncryptionKey();
        if ($encryptionKey === null) throw new \LogicException('TENANT_PAYMENT_ENCRYPTION_KEY must be 32 raw bytes or base64: followed by a valid 32-byte key.');
        $apiKey = trim($apiKey);
        $webhookSecret = trim($webhookSecret);
        if ($apiKey === '' || strlen($apiKey) > 500 || $webhookSecret === '' || strlen($webhookSecret) > 500
            || preg_match('/[\x00-\x1F\x7F]/', $apiKey.$webhookSecret) === 1) {
            throw new \InvalidArgumentException('La clé API et le secret webhook sont obligatoires.');
        }
        $currentEnvironment = $this->connection->fetchOne('SELECT environment FROM tenant_payment_settings WHERE tenant_id = ?', [$tenant->tenantId->value]);
        if (is_string($currentEnvironment) && $currentEnvironment !== $environment) {
            throw new \DomainException('Révoquez la configuration existante avant de changer d’environnement.');
        }
        if ((int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM payment_attempts WHERE tenant_id = ? AND state IN ('initiated', 'pending')",
            [$tenant->tenantId->value],
        ) > 0) {
            throw new \DomainException('Des paiements sont encore en attente. Terminez leur rapprochement avant de remplacer les identifiants marchands.');
        }

        $credentials = json_encode(['apiKey' => $apiKey, 'webhookSecret' => $webhookSecret], JSON_THROW_ON_ERROR);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $associatedData = self::associatedData($tenant->tenantId->value, $provider, $environment);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($credentials, $associatedData, $nonce, $encryptionKey);
        $encrypted = 'v2:'.base64_encode($nonce.$ciphertext);
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $sql = 'INSERT INTO tenant_payment_settings (tenant_id, provider, environment, encrypted_credentials, last_api_check_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)';
        $sql .= $this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform
            ? ' ON DUPLICATE KEY UPDATE provider = VALUES(provider), environment = VALUES(environment), encrypted_credentials = VALUES(encrypted_credentials), last_api_check_at = VALUES(last_api_check_at), updated_at = VALUES(updated_at)'
            : ' ON CONFLICT (tenant_id) DO UPDATE SET provider = excluded.provider, environment = excluded.environment, encrypted_credentials = excluded.encrypted_credentials, last_api_check_at = excluded.last_api_check_at, updated_at = excluded.updated_at';
        $this->connection->executeStatement($sql, [$tenant->tenantId->value, $provider, $environment, $encrypted, $now, $now]);

        return ['provider' => $provider, 'environment' => $environment, 'configured' => true, 'lastApiCheckAt' => $now, 'updatedAt' => $now];
    }

    /** Internal use only. Never expose this result from a controller. @return array{apiKey: string, webhookSecret: string}|null */
    public function credentials(string $tenantId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT provider, environment, encrypted_credentials FROM tenant_payment_settings WHERE tenant_id = ?', [$tenantId],
        );
        if ($row === false) return null;
        $encryptionKey = $this->resolvedEncryptionKey();
        if ($encryptionKey === null) throw new \LogicException('TENANT_PAYMENT_ENCRYPTION_KEY must be 32 raw bytes or base64: followed by a valid 32-byte key.');
        $stored = (string) $row['encrypted_credentials'];
        $versionTwo = str_starts_with($stored, 'v2:');
        $payload = base64_decode($versionTwo ? substr($stored, 3) : $stored, true);
        $nonceBytes = $versionTwo ? SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES : SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        if (!is_string($payload) || strlen($payload) <= $nonceBytes) {
            throw new \RuntimeException('Stored payment credentials are invalid.');
        }
        $nonce = substr($payload, 0, $nonceBytes);
        $ciphertext = substr($payload, $nonceBytes);
        $plain = $versionTwo
            ? sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ciphertext, self::associatedData($tenantId, (string) $row['provider'], (string) $row['environment']), $nonce, $encryptionKey)
            : sodium_crypto_secretbox_open($ciphertext, $nonce, $encryptionKey);
        if (!is_string($plain)) throw new \RuntimeException('Stored payment credentials could not be decrypted.');
        $credentials = json_decode($plain, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($credentials) || !is_string($credentials['apiKey'] ?? null) || !is_string($credentials['webhookSecret'] ?? null)) {
            throw new \RuntimeException('Stored payment credentials are incomplete.');
        }
        return ['apiKey' => $credentials['apiKey'], 'webhookSecret' => $credentials['webhookSecret']];
    }

    /** @return array{tenantId: string, provider: string, environment: string, apiKey: string, webhookSecret: string}|null */
    public function forTenant(string $tenantId): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT provider, environment FROM tenant_payment_settings WHERE tenant_id = ?', [$tenantId]);
        $credentials = $this->credentials($tenantId);
        if ($row === false || $credentials === null) return null;
        return ['tenantId' => $tenantId, 'provider' => (string) $row['provider'], 'environment' => (string) $row['environment'], ...$credentials];
    }

    /** Resolve an untrusted provider reference to all possible tenant configurations for signature matching. */
    public function forPaymentReference(string $provider, string $providerReference): array
    {
        if ($providerReference === '' || strlen($providerReference) > 200) return [];
        $tenantIds = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT tenant_id FROM payment_attempts WHERE provider = ? AND provider_reference = ?',
            [$provider, $providerReference],
        );
        $configurations = [];
        foreach ($tenantIds as $tenantId) {
            $settings = $this->forTenant((string) $tenantId);
            if ($settings !== null && $settings['provider'] === $provider) $configurations[] = $settings;
        }
        return $configurations;
    }

    public function revoke(TenantContext $tenant): void
    {
        $this->assertPermission($tenant);
        if ((int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM payment_attempts WHERE tenant_id = ? AND state IN ('initiated', 'pending')",
            [$tenant->tenantId->value],
        ) > 0) {
            throw new \DomainException('Des paiements sont encore en attente. Terminez leur rapprochement avant de révoquer les identifiants marchands.');
        }
        $this->connection->delete('tenant_payment_settings', ['tenant_id' => $tenant->tenantId->value]);
    }

    private function resolvedEncryptionKey(): ?string
    {
        if (str_starts_with($this->encryptionKey, 'base64:')) {
            $decoded = base64_decode(substr($this->encryptionKey, 7), true);
            return is_string($decoded) && strlen($decoded) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES ? $decoded : null;
        }

        return strlen($this->encryptionKey) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES ? $this->encryptionKey : null;
    }

    private function assertPermission(TenantContext $tenant): void
    {
        if (!$tenant->allows(TenantPermission::ManagePaymentSettings)) {
            throw new \DomainException('Access denied for this tenant operation.');
        }
    }

    private static function associatedData(string $tenantId, string $provider, string $environment): string
    {
        return implode("\0", ['miksprit-tenant-payment-v2', $tenantId, $provider, $environment]);
    }
}
