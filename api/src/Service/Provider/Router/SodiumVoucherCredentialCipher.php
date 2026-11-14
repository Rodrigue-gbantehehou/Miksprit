<?php

declare(strict_types=1);

namespace App\Service\Provider\Router;

use App\Entity\TenantId;
use App\Service\Router\VoucherCredentialCipher;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** XChaCha20-Poly1305 envelope encryption. The base64 key is mandatory and has no fallback. */
#[AsAlias(VoucherCredentialCipher::class)]
final readonly class SodiumVoucherCredentialCipher implements VoucherCredentialCipher
{
    private const VERSION = 'v1';
    private const ASSOCIATED_DATA_PREFIX = 'miksprit:voucher-credential:v1';

    private string $key;

    public function __construct(#[Autowire('%env(VOUCHER_CREDENTIAL_ENCRYPTION_KEY)%')] string $base64Key)
    {
        if (!function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            throw new \LogicException('The sodium extension is required to encrypt voucher credentials.');
        }
        $key = base64_decode($base64Key, true);
        if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new \InvalidArgumentException('VOUCHER_CREDENTIAL_ENCRYPTION_KEY must be base64 for exactly 32 random bytes.');
        }
        $this->key = $key;
    }

    public function encrypt(TenantId $tenantId, string $voucherId, string $username, string $password): string
    {
        self::assertVoucherId($voucherId);
        if ($username === '' || $password === '') {
            throw new \InvalidArgumentException('Voucher credentials must not be empty.');
        }
        $plaintext = json_encode(['username' => $username, 'password' => $password], JSON_THROW_ON_ERROR);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        try {
            $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, self::associatedData($tenantId, $voucherId), $nonce, $this->key);
        } finally {
            sodium_memzero($plaintext);
        }

        return self::VERSION.'.'.rtrim(strtr(base64_encode($nonce.$ciphertext), '+/', '-_'), '=');
    }

    public function assertConfigured(): void
    {
        // Construction validates the extension and key; this method intentionally exposes no key material.
    }

    /** @return array{username: string, password: string} */
    public function decrypt(TenantId $tenantId, string $voucherId, string $envelope): array
    {
        self::assertVoucherId($voucherId);
        if (!str_starts_with($envelope, self::VERSION.'.')) {
            throw new \InvalidArgumentException('Unsupported voucher credential envelope.');
        }
        $encoded = substr($envelope, strlen(self::VERSION) + 1);
        if ($encoded === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $encoded) !== 1) {
            throw new \InvalidArgumentException('Malformed voucher credential envelope.');
        }
        $payload = base64_decode(strtr($encoded, '-_', '+/').str_repeat('=', (4 - strlen($encoded) % 4) % 4), true);
        $nonceLength = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if (!is_string($payload) || strlen($payload) <= $nonceLength) {
            throw new \InvalidArgumentException('Malformed voucher credential envelope.');
        }
        $nonce = substr($payload, 0, $nonceLength);
        $ciphertext = substr($payload, $nonceLength);
        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ciphertext, self::associatedData($tenantId, $voucherId), $nonce, $this->key);
        if (!is_string($plaintext)) {
            throw new \RuntimeException('Voucher credential authentication failed.');
        }

        try {
            $credentials = json_decode($plaintext, true, 4, JSON_THROW_ON_ERROR);
        } finally {
            sodium_memzero($plaintext);
        }
        if (!is_array($credentials) || !is_string($credentials['username'] ?? null) || !is_string($credentials['password'] ?? null)) {
            throw new \RuntimeException('Voucher credential payload is invalid.');
        }

        return ['username' => $credentials['username'], 'password' => $credentials['password']];
    }

    private static function associatedData(TenantId $tenantId, string $voucherId): string
    {
        return self::ASSOCIATED_DATA_PREFIX.':'.$tenantId->value.':'.$voucherId;
    }

    private static function assertVoucherId(string $voucherId): void
    {
        if (preg_match('/^[A-Za-z0-9_-]{8,80}$/D', $voucherId) !== 1) {
            throw new \InvalidArgumentException('Voucher ID for credential encryption is invalid.');
        }
    }
}
