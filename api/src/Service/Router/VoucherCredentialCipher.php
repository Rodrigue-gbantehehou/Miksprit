<?php

declare(strict_types=1);

namespace App\Service\Router;

use App\Entity\TenantId;

/** Implement with authenticated encryption backed by a managed application key. */
interface VoucherCredentialCipher
{
    public function assertConfigured(): void;

    public function encrypt(TenantId $tenantId, string $voucherId, string $username, string $password): string;

    /** @return array{username: string, password: string} */
    public function decrypt(TenantId $tenantId, string $voucherId, string $envelope): array;
}
