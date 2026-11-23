<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'hotspot_sessions')]
#[ORM\Index(name: 'idx_session_tenant_state', columns: ['tenant_id', 'state'])]
#[ORM\Index(name: 'idx_session_router_external_state', columns: ['router_id', 'external_session_id', 'state'])]
final class HotspotSession implements TenantOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 80)]
    public readonly string $id;

    #[ORM\Embedded(class: TenantId::class, columnPrefix: false)]
    private readonly TenantId $ownerTenantId;

    #[ORM\Column(name: 'router_id', type: 'string', length: 80)]
    public readonly string $routerId;

    #[ORM\Column(name: 'voucher_id', type: 'string', length: 80)]
    public readonly string $voucherId;

    #[ORM\Column(name: 'external_session_id', type: 'string', length: 160)]
    public readonly string $externalSessionId;

    #[ORM\Column(name: 'state', enumType: HotspotSessionStatus::class, length: 16)]
    private HotspotSessionStatus $sessionStatus = HotspotSessionStatus::Queued;

    #[ORM\Column(name: 'started_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(name: 'ended_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    /** Router accounting is unknown until reported; null is not equivalent to zero. */
    #[ORM\Column(name: 'bytes_in', type: 'bigint', nullable: true)]
    private ?string $bytesIn = null;

    #[ORM\Column(name: 'bytes_out', type: 'bigint', nullable: true)]
    private ?string $bytesOut = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    public readonly \DateTimeImmutable $createdAt;

    public function __construct(
        string $id,
        TenantId $tenantId,
        string $routerId,
        string $voucherId,
        string $externalSessionId,
        ?\DateTimeImmutable $createdAt = null,
    ) {
        foreach ([$id, $routerId, $voucherId, $externalSessionId] as $value) {
            if (trim($value) === '' || strlen($value) > 160) {
                throw new \InvalidArgumentException('Hotspot session identifiers must be present and bounded.');
            }
        }
        $this->id = $id;
        $this->ownerTenantId = $tenantId;
        $this->routerId = $routerId;
        $this->voucherId = $voucherId;
        $this->externalSessionId = $externalSessionId;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
    }

    public function tenantId(): TenantId
    {
        return $this->ownerTenantId;
    }

    public function status(): HotspotSessionStatus
    {
        return $this->sessionStatus;
    }

    public function startTime(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function endTime(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }

    /** Mark active only from a positive router accounting/event response. */
    public function confirmStarted(\DateTimeImmutable $observedAt): void
    {
        if ($this->sessionStatus !== HotspotSessionStatus::Queued) {
            throw new \DomainException('Only a queued session can be confirmed active.');
        }
        $this->sessionStatus = HotspotSessionStatus::Active;
        $this->startedAt = $observedAt;
    }

    /** Null counters mean RouterOS did not provide accounting values. */
    public function recordAccounting(?string $bytesIn, ?string $bytesOut): void
    {
        if ($this->sessionStatus !== HotspotSessionStatus::Active) {
            throw new \DomainException('Accounting can only be recorded for an active session.');
        }
        foreach ([$bytesIn, $bytesOut] as $counter) {
            if ($counter !== null && preg_match('/^(0|[1-9][0-9]{0,18})$/D', $counter) !== 1) {
                throw new \InvalidArgumentException('Accounting counters must be non-negative integer byte strings.');
            }
        }
        $this->bytesIn = $bytesIn;
        $this->bytesOut = $bytesOut;
    }

    public function end(HotspotSessionStatus $finalStatus, \DateTimeImmutable $observedAt): void
    {
        if ($this->sessionStatus !== HotspotSessionStatus::Active
            || !in_array($finalStatus, [HotspotSessionStatus::Ended, HotspotSessionStatus::Disconnected, HotspotSessionStatus::Failed], true)) {
            throw new \DomainException('An active session must end with a valid terminal status.');
        }
        if ($this->startedAt !== null && $observedAt < $this->startedAt) {
            throw new \InvalidArgumentException('Session end time cannot precede its start time.');
        }
        $this->sessionStatus = $finalStatus;
        $this->endedAt = $observedAt;
    }
}
