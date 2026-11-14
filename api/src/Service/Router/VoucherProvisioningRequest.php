<?php

declare(strict_types=1);

namespace App\Service\Router;

final readonly class VoucherProvisioningRequest
{
    public function __construct(
        public string $routerId,
        public string $voucherId,
        public string $username,
        private string $password,
        public string $profile,
        public \DateTimeImmutable $expiresAt,
        public ?string $limitUptime = null,
        public ?int $limitBytes = null,
        public int $sharedUsers = 1,
    ) {
        foreach ([$routerId, $voucherId, $username, $password, $profile] as $value) {
            if ($value === '' || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                throw new \InvalidArgumentException('Voucher provisioning input is invalid.');
            }
        }
        if (($limitBytes !== null && $limitBytes < 1) || $sharedUsers < 1 || $sharedUsers > 64) {
            throw new \InvalidArgumentException('Voucher usage limits are invalid.');
        }
    }

    public function __debugInfo(): array
    {
        return [
            'routerId' => $this->routerId,
            'voucherId' => $this->voucherId,
            'username' => $this->username,
            'password' => '[redacted]',
            'profile' => $this->profile,
            'expiresAt' => $this->expiresAt,
            'limitUptime' => $this->limitUptime,
            'limitBytes' => $this->limitBytes,
            'sharedUsers' => $this->sharedUsers,
        ];
    }

    public function passwordForProvisioning(): string
    {
        return $this->password;
    }

    public function __serialize(): array
    {
        throw new \LogicException('Voucher credentials must never be serialized.');
    }
}
