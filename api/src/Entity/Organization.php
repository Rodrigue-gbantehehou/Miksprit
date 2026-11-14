<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Tenant-owned organization metadata; the opaque tenant ID is its stable identity. */
#[ORM\Entity]
#[ORM\Table(name: 'organizations')]
#[ORM\UniqueConstraint(name: 'uniq_organization_slug', columns: ['slug'])]
final class Organization implements TenantOwned
{
    #[ORM\Id]
    #[ORM\Column(name: 'tenant_id', type: 'string', length: 80)]
    public readonly string $id;

    #[ORM\Column(name: 'name', type: 'string', length: 160)]
    private string $organizationName;

    #[ORM\Column(type: 'string', length: 80)]
    public readonly string $slug;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    public readonly \DateTimeImmutable $createdAt;

    #[ORM\Column(enumType: OrganizationStatus::class, length: 16)]
    private OrganizationStatus $organizationStatus = OrganizationStatus::Active;

    private function __construct(
        TenantId $id,
        string $organizationName,
        string $slug,
        \DateTimeImmutable $createdAt,
    ) {
        $this->id = $id->value;
        $this->organizationName = $organizationName;
        $this->slug = $slug;
        $this->createdAt = $createdAt;
        self::assertName($organizationName);
        if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,78}[a-z0-9])?$/D', $slug) !== 1) {
            throw new \InvalidArgumentException('Organization slug must be lowercase and URL-safe.');
        }
    }

    public static function create(
        TenantId $id,
        string $name,
        string $slug,
        ?\DateTimeImmutable $createdAt = null,
    ): self {
        return new self($id, trim($name), $slug, $createdAt ?? new \DateTimeImmutable());
    }

    public function tenantId(): TenantId
    {
        return new TenantId($this->id);
    }

    public function name(): string
    {
        return $this->organizationName;
    }

    public function status(): OrganizationStatus
    {
        return $this->organizationStatus;
    }

    public function rename(TenantContext $actor, string $name): void
    {
        $this->assertOrganizationManager($actor);
        self::assertName(trim($name));
        $this->organizationName = trim($name);
    }

    public function suspend(TenantContext $actor): void
    {
        $this->assertOrganizationManager($actor);
        $this->organizationStatus = OrganizationStatus::Suspended;
    }

    public function reactivate(TenantContext $actor): void
    {
        $this->assertOrganizationManager($actor);
        $this->organizationStatus = OrganizationStatus::Active;
    }

    private function assertOrganizationManager(TenantContext $actor): void
    {
        if (!$actor->tenantId->equals($this->tenantId()) || !$actor->allows(TenantPermission::ManageOrganization)) {
            throw new \DomainException('Actor is not allowed to manage this organization.');
        }
    }

    private static function assertName(string $name): void
    {
        $length = iconv_strlen($name, 'UTF-8');
        if ($name === '' || $length === false || $length > 160) {
            throw new \InvalidArgumentException('Organization name must contain between 1 and 160 characters.');
        }
    }
}
