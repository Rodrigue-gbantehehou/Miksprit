<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

use App\Entity\XofAmount;
use App\Entity\TenantId;
use App\Entity\TenantOwned;

/** A tenant-owned Wi-Fi offer; published values are snapshotted when an order is created. */
#[ORM\Entity]
#[ORM\Table(name: 'access_plans')]
#[ORM\Index(name: 'idx_plan_site_state', columns: ['site_id', 'publication_state'])]
final class AccessPlan implements TenantOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 80)]
    public readonly string $id;

    #[ORM\Embedded(class: TenantId::class, columnPrefix: false)]
    private readonly TenantId $ownerTenantId;

    #[ORM\Column(name: 'site_id', type: 'string', length: 80)]
    public readonly string $siteId;

    #[ORM\Column(name: 'publication_state', enumType: PlanPublicationState::class, length: 16)]
    private PlanPublicationState $publicationState = PlanPublicationState::Draft;

    public function __construct(
        string $id,
        TenantId $ownerTenantId,
        string $siteId,
        #[ORM\Column(type: 'string', length: 120)] private string $name,
        #[ORM\Embedded(class: XofAmount::class, columnPrefix: 'price_')] private XofAmount $price,
        #[ORM\Column(name: 'session_seconds', type: 'integer')] private int $sessionSeconds,
        #[ORM\Column(name: 'data_limit_bytes', type: 'integer', nullable: true)] private ?int $dataLimitBytes,
        #[ORM\Column(name: 'device_limit', type: 'smallint')] private int $deviceLimit = 1,
        #[ORM\Column(type: 'integer')] private int $version = 1,
    ) {
        $this->id = $id;
        $this->ownerTenantId = $ownerTenantId;
        $this->siteId = $siteId;
        if ($id === '' || $siteId === '' || trim($name) === '' || strlen($name) > 120
            || $price->minorUnits < 1 || $sessionSeconds < 1 || $deviceLimit < 1
            || ($dataLimitBytes !== null && $dataLimitBytes < 1) || $version < 1) {
            throw new \InvalidArgumentException('Access plan contains invalid values.');
        }
    }

    public function tenantId(): TenantId
    {
        return $this->ownerTenantId;
    }

    public function publicationState(): PlanPublicationState
    {
        return $this->publicationState;
    }

    public function updateTerms(string $name, XofAmount $price, int $sessionSeconds, ?int $dataLimitBytes, int $deviceLimit): void
    {
        if ($this->publicationState === PlanPublicationState::Archived) {
            throw new \DomainException('Archived plans cannot be changed.');
        }
        if (trim($name) === '' || strlen($name) > 120 || $price->minorUnits < 1 || $sessionSeconds < 1
            || $deviceLimit < 1 || ($dataLimitBytes !== null && $dataLimitBytes < 1)) {
            throw new \InvalidArgumentException('Access plan contains invalid values.');
        }

        $this->name = $name;
        $this->price = $price;
        $this->sessionSeconds = $sessionSeconds;
        $this->dataLimitBytes = $dataLimitBytes;
        $this->deviceLimit = $deviceLimit;
        ++$this->version;
    }

    public function publish(): void
    {
        if ($this->publicationState === PlanPublicationState::Archived) {
            throw new \DomainException('Archived plans cannot be published.');
        }
        $this->publicationState = PlanPublicationState::Published;
    }

    public function unpublish(): void
    {
        if ($this->publicationState !== PlanPublicationState::Published) {
            throw new \DomainException('Only published plans can be unpublished.');
        }
        $this->publicationState = PlanPublicationState::Draft;
    }

    public function archive(): void
    {
        $this->publicationState = PlanPublicationState::Archived;
    }

    public function snapshot(): PlanSnapshot
    {
        if ($this->publicationState !== PlanPublicationState::Published) {
            throw new \DomainException('Only a published plan can be purchased.');
        }

        return new PlanSnapshot(
            $this->id,
            $this->siteId,
            $this->ownerTenantId,
            $this->version,
            $this->name,
            $this->price,
            $this->sessionSeconds,
            $this->dataLimitBytes,
            $this->deviceLimit,
        );
    }
}
