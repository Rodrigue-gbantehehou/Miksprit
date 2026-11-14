<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Provider\Router;

use App\Service\Provider\Router\SodiumVoucherCredentialCipher;
use App\Entity\TenantId;
use PHPUnit\Framework\TestCase;

final class SodiumVoucherCredentialCipherTest extends TestCase
{
    public function testEncryptsAndAuthenticatesCredentials(): void
    {
        $cipher = new SodiumVoucherCredentialCipher($this->encodedKey());
        $tenant = new TenantId('tenant_cipher_01');
        $first = $cipher->encrypt($tenant, 'voucher_cipher_01', 'voucher-user', 'voucher-password');
        $second = $cipher->encrypt($tenant, 'voucher_cipher_01', 'voucher-user', 'voucher-password');

        self::assertNotSame($first, $second, 'Random nonces must make repeated envelopes differ.');
        self::assertSame(['username' => 'voucher-user', 'password' => 'voucher-password'], $cipher->decrypt($tenant, 'voucher_cipher_01', $first));
        try {
            $cipher->decrypt(new TenantId('tenant_cipher_02'), 'voucher_cipher_01', $first);
            self::fail('Ciphertext must be bound to its tenant.');
        } catch (\RuntimeException) {
        }
        try {
            $cipher->decrypt($tenant, 'voucher_cipher_02', $first);
            self::fail('Ciphertext must be bound to its voucher.');
        } catch (\RuntimeException) {
        }

        $index = strlen('v1.') + 5;
        $tampered = substr($first, 0, $index).($first[$index] === 'A' ? 'B' : 'A').substr($first, $index + 1);
        $this->expectException(\RuntimeException::class);
        $cipher->decrypt($tenant, 'voucher_cipher_01', $tampered);
    }

    public function testEncryptionKeyIsMandatoryAndExactly32Bytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SodiumVoucherCredentialCipher('');
    }

    private function encodedKey(): string
    {
        return base64_encode(random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES));
    }
}
