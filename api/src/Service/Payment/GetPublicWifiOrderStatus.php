<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Entity\TenantId;
use App\Repository\PublicCheckoutRepository;
use App\Service\Router\VoucherCredentialCipher;

final readonly class GetPublicWifiOrderStatus
{
    public function __construct(private PublicCheckoutRepository $repository, private VoucherCredentialCipher $cipher)
    {
    }

    /** @return array{reference: string, paymentStatus: string, orderStatus: string, access: array{username: string, password: string}|null, accessExpired: bool}|null */
    public function execute(string $reference): ?array
    {
        $row = $this->repository->findPublicOrderStatus($reference);
        if ($row === null) return null;
        $access = null;
        if ($row['orderStatus'] === 'fulfilled' && $row['voucherStatus'] === 'active' && $row['voucherId'] !== null && $row['encryptedCredential'] !== null) {
            $access = $this->cipher->decrypt(new TenantId((string) $row['tenantId']), $row['voucherId'], $row['encryptedCredential']);
        }
        return ['reference' => $row['reference'], 'paymentStatus' => $row['paymentStatus'], 'orderStatus' => $row['orderStatus'], 'access' => $access, 'accessExpired' => $row['voucherStatus'] === 'expired'];
    }
}
