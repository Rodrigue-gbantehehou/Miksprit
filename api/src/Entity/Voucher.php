<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'vouchers')]
#[ORM\UniqueConstraint(name: 'uniq_voucher_tenant_code_hash', columns: ['tenant_id', 'code_hash'])]
#[ORM\UniqueConstraint(name: 'uniq_voucher_order', columns: ['tenant_id', 'order_reference'])]
#[ORM\Index(name: 'idx_voucher_site_status', columns: ['site_id', 'status'])]
#[ORM\Index(name: 'idx_voucher_expiry_router', columns: ['router_id', 'status', 'expires_at'])]
final class Voucher implements TenantOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 80)]
    public readonly string $id;

    #[ORM\Embedded(class: TenantId::class, columnPrefix: false)]
    private readonly TenantId $ownerTenantId;

    #[ORM\Column(name: 'site_id', type: 'string', length: 80)]
    public readonly string $siteId;

    #[ORM\Column(name: 'router_id', type: 'string', length: 80, nullable: true)]
    private ?string $routerId;

    #[ORM\Column(name: 'order_reference', type: 'string', length: 160, nullable: true)]
    public readonly ?string $orderReference;

    /** One-way hash supports lookup without exposing a credential; encrypted code is returned only through a dedicated secret flow. */
    #[ORM\Column(name: 'code_hash', type: 'string', length: 64)]
    private readonly string $codeHash;

    #[ORM\Column(name: 'encrypted_credential', type: 'text', nullable: true)]
    private ?string $encryptedCredential = null;

    #[ORM\Column(name: 'status', enumType: VoucherStatus::class, length: 16)]
    private VoucherStatus $voucherStatus = VoucherStatus::Reserved;

    #[ORM\Column(name: 'expires_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    public readonly \DateTimeImmutable $createdAt;

    public function __construct(
        string $id,
        TenantId $tenantId,
        string $siteId,
        string $codeHash,
        ?string $orderReference = null,
        ?\DateTimeImmutable $createdAt = null,
        ?string $routerId = null,
    ) {
        if (preg_match('/^[A-Za-z0-9_-]{8,80}$/D', $id) !== 1 || preg_match('/^[A-Za-z0-9_-]{8,80}$/D', $siteId) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $codeHash) !== 1
            || ($orderReference !== null && (trim($orderReference) === '' || strlen($orderReference) > 160))) {
            throw new \InvalidArgumentException('Voucher identifiers or code hash are invalid.');
        }
        $this->id = $id;
        $this->ownerTenantId = $tenantId;
        $this->siteId = $siteId;
        $this->codeHash = $codeHash;
        $this->orderReference = $orderReference;
        if ($routerId !== null && preg_match('/^[A-Za-z0-9_-]{8,80}$/D', $routerId) !== 1) throw new \InvalidArgumentException('Voucher router ID is invalid.');
        $this->routerId = $routerId;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
    }

    public function tenantId(): TenantId
    {
        return $this->ownerTenantId;
    }

    public function status(): VoucherStatus
    {
        return $this->voucherStatus;
    }

    public function expiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function routerId(): ?string
    {
        return $this->routerId;
    }

    /** Activate only after a router confirms that it accepted the encrypted credential. */
    public function markProvisioned(string $encryptedCredential, \DateTimeImmutable $expiresAt): void
    {
        if ($this->voucherStatus !== VoucherStatus::Reserved || trim($encryptedCredential) === '' || $expiresAt <= new \DateTimeImmutable()) {
            throw new \DomainException('Voucher requires a reserved state, encrypted credential, and future expiry.');
        }
        $this->encryptedCredential = $encryptedCredential;
        $this->expiresAt = $expiresAt;
        $this->voucherStatus = VoucherStatus::Active;
    }

    public function prepareCredential(string $encryptedCredential): void
    {
        if ($this->voucherStatus !== VoucherStatus::Reserved || trim($encryptedCredential) === '') {
            throw new \DomainException('Only a reserved voucher can store a prepared credential.');
        }
        $this->encryptedCredential = $encryptedCredential;
    }

    public function preparedCredential(): ?string
    {
        return $this->voucherStatus === VoucherStatus::Reserved ? $this->encryptedCredential : null;
    }

    public function encryptedCredential(): ?string
    {
        return $this->encryptedCredential;
    }

    public function expire(\DateTimeImmutable $now): void
    {
        if ($this->voucherStatus !== VoucherStatus::Active || $this->expiresAt === null || $now < $this->expiresAt) {
            throw new \DomainException('Only a due active voucher can expire.');
        }
        $this->voucherStatus = VoucherStatus::Expired;
        $this->encryptedCredential = null;
    }

    public function revoke(): void
    {
        if ($this->voucherStatus === VoucherStatus::Revoked) {
            return;
        }
        if ($this->voucherStatus === VoucherStatus::Expired) {
            throw new \DomainException('Expired vouchers cannot be revoked.');
        }
        $this->voucherStatus = VoucherStatus::Revoked;
        $this->encryptedCredential = null;
    }
}
