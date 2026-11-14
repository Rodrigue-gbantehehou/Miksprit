<?php

declare(strict_types=1);

namespace App\Service\Router;

use App\Entity\VoucherStatus;
use App\Repository\ExpiredVoucherRepository;

final readonly class ExpireDueVouchers
{
    public function __construct(
        private ExpiredVoucherRepository $repository,
        private RouterManagementFactory $routers,
        private VoucherCredentialCipher $cipher,
        private VoucherProvisioningIdempotencyStore $idempotency,
    ) {
    }

    /** @return array{expired: int, skipped: int, unassigned: int} */
    public function execute(int $limit = 100): array
    {
        if ($limit < 1 || $limit > 500) throw new \InvalidArgumentException('Voucher expiration batch limit is invalid.');
        $this->cipher->assertConfigured();
        $expired = 0;
        $skipped = 0;
        foreach ($this->repository->due($limit) as $item) {
            $voucher = $item['voucher'];
            $key = 'voucher-expiration:'.$voucher->tenantId()->value.':'.$voucher->id;
            if (!$this->idempotency->claim($key)) { ++$skipped; continue; }
            try {
                $envelope = $voucher->encryptedCredential();
                if ($envelope === null || $voucher->status() !== VoucherStatus::Active) throw new \DomainException('Active voucher credential is unavailable.');
                $credentials = $this->cipher->decrypt($voucher->tenantId(), $voucher->id, $envelope);
                // RouterOS removal is safe to repeat: absence of this exact generated username is success.
                $this->routers->forRouterManagement($item['router'])->revokeUser($credentials['username']);
                $voucher->expire(new \DateTimeImmutable());
                $this->repository->complete($voucher);
                $this->idempotency->complete($key);
                ++$expired;
            } catch (\Throwable) {
                try { $this->idempotency->release($key); } catch (\Throwable) { /* keep retry lease if persistence is uncertain */ }
                ++$skipped;
            }
        }
        return ['expired' => $expired, 'skipped' => $skipped, 'unassigned' => $this->repository->unassignedDueCount()];
    }
}
