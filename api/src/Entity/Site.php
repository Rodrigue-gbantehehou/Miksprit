<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'sites')]
#[ORM\UniqueConstraint(name: 'uniq_site_tenant_slug', columns: ['tenant_id', 'slug'])]
#[ORM\UniqueConstraint(name: 'uniq_site_public_token_hash', columns: ['public_token_hash'])]
#[ORM\Index(name: 'idx_site_tenant_status', columns: ['tenant_id', 'status'])]
final class Site implements TenantOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 80)]
    public readonly string $id;

    #[ORM\Embedded(class: TenantId::class, columnPrefix: false)]
    private readonly TenantId $ownerTenantId;

    #[ORM\Column(type: 'string', length: 120)]
    private string $siteName;

    #[ORM\Column(type: 'string', length: 80)]
    public readonly string $slug;

    /** Store only a cryptographic hash of the opaque public portal token. */
    #[ORM\Column(name: 'public_token_hash', type: 'string', length: 64)]
    private readonly string $publicTokenHash;

    #[ORM\Column(name: 'status', enumType: SiteStatus::class, length: 16)]
    private SiteStatus $siteStatus = SiteStatus::Draft;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    public readonly \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $id,
        TenantId $tenantId,
        string $name,
        string $slug,
        string $publicTokenHash,
        ?\DateTimeImmutable $createdAt = null,
    ) {
        if (!self::validId($id) || trim($name) === '' || mb_strlen($name) > 120
            || preg_match('/^[a-z0-9](?:[a-z0-9-]{0,78}[a-z0-9])?$/D', $slug) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $publicTokenHash) !== 1) {
            throw new \InvalidArgumentException('Site identifiers, name, slug, or public token hash are invalid.');
        }

        $this->id = $id;
        $this->ownerTenantId = $tenantId;
        $this->siteName = trim($name);
        $this->slug = $slug;
        $this->publicTokenHash = $publicTokenHash;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function tenantId(): TenantId
    {
        return $this->ownerTenantId;
    }

    public function name(): string
    {
        return $this->siteName;
    }

    public function status(): SiteStatus
    {
        return $this->siteStatus;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function rename(string $name): void
    {
        if (trim($name) === '' || mb_strlen($name) > 120) {
            throw new \InvalidArgumentException('Site name must contain between 1 and 120 characters.');
        }
        $this->siteName = trim($name);
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function activate(): void
    {
        if ($this->siteStatus === SiteStatus::Suspended) {
            throw new \DomainException('Suspended sites must be reactivated by an authorized owner first.');
        }
        $this->siteStatus = SiteStatus::Active;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function suspend(): void
    {
        $this->siteStatus = SiteStatus::Suspended;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function reactivate(): void
    {
        if ($this->siteStatus !== SiteStatus::Suspended) {
            throw new \DomainException('Only a suspended site can be reactivated.');
        }
        $this->siteStatus = SiteStatus::Active;
        $this->updatedAt = new \DateTimeImmutable();
    }

    private static function validId(string $id): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{8,80}$/D', $id) === 1;
    }
}
