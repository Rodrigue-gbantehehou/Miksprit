<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'routers')]
#[ORM\UniqueConstraint(name: 'uniq_router_tenant_site_name', columns: ['tenant_id', 'site_id', 'router_name'])]
#[ORM\Index(name: 'idx_router_tenant_status', columns: ['tenant_id', 'status'])]
final class Router implements TenantOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 80)]
    public readonly string $id;

    #[ORM\Embedded(class: TenantId::class, columnPrefix: false)]
    private readonly TenantId $ownerTenantId;

    #[ORM\Column(name: 'site_id', type: 'string', length: 80)]
    public readonly string $siteId;

    #[ORM\Column(name: 'router_name', type: 'string', length: 120)]
    private string $routerName;

    #[ORM\Column(name: 'host', type: 'string', length: 253)]
    private readonly string $host;

    #[ORM\Column(name: 'api_port', type: 'smallint')]
    private readonly int $apiPort;

    /** Reference in a secret manager; RouterOS passwords never live in this entity. */
    #[ORM\Column(name: 'credential_reference', type: 'string', length: 160)]
    private readonly string $credentialReference;

    #[ORM\Column(name: 'provider_code', type: 'string', length: 32, options: ['default' => 'mikrotik'])]
    private readonly string $providerCode;

    #[ORM\Column(name: 'router_os_version', type: 'string', length: 32, nullable: true)]
    private ?string $routerOsVersion = null;

    #[ORM\Column(name: 'status', enumType: RouterStatus::class, length: 24)]
    private RouterStatus $routerStatus = RouterStatus::PendingEnrollment;

    #[ORM\Column(name: 'last_seen_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastSeenAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    public readonly \DateTimeImmutable $createdAt;

    public function __construct(
        string $id,
        TenantId $tenantId,
        string $siteId,
        string $name,
        string $host,
        int $apiPort,
        string $credentialReference,
        ?\DateTimeImmutable $createdAt = null,
        string $providerCode = 'mikrotik',
    ) {
        if (preg_match('/^[A-Za-z0-9_-]{8,80}$/D', $id) !== 1 || preg_match('/^[A-Za-z0-9_-]{8,80}$/D', $siteId) !== 1
            || trim($name) === '' || mb_strlen($name) > 120 || !self::validHost($host)
            || $apiPort < 1 || $apiPort > 65535 || trim($credentialReference) === '' || strlen($credentialReference) > 160
            || preg_match('/^[a-z][a-z0-9_-]{1,31}$/D', $providerCode) !== 1) {
            throw new \InvalidArgumentException('Router enrollment data is invalid.');
        }

        $this->id = $id;
        $this->ownerTenantId = $tenantId;
        $this->siteId = $siteId;
        $this->routerName = trim($name);
        $this->host = $host;
        $this->apiPort = $apiPort;
        $this->credentialReference = $credentialReference;
        $this->providerCode = $providerCode;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
    }

    public function tenantId(): TenantId
    {
        return $this->ownerTenantId;
    }

    public function status(): RouterStatus
    {
        return $this->routerStatus;
    }

    public function providerCode(): string
    {
        return $this->providerCode;
    }

    public function lastSeenAt(): ?\DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    /** Call only after a verified RouterOS health check. */
    public function markReachable(string $routerOsVersion, \DateTimeImmutable $observedAt): void
    {
        if (trim($routerOsVersion) === '' || strlen($routerOsVersion) > 32) {
            throw new \InvalidArgumentException('RouterOS version must be present and bounded.');
        }
        $this->routerOsVersion = $routerOsVersion;
        $this->lastSeenAt = $observedAt;
        $this->routerStatus = RouterStatus::Active;
    }

    public function markOffline(\DateTimeImmutable $observedAt): void
    {
        if ($this->routerStatus !== RouterStatus::Suspended) {
            $this->routerStatus = RouterStatus::Offline;
        }
        $this->lastSeenAt = $observedAt;
    }

    public function suspend(): void
    {
        $this->routerStatus = RouterStatus::Suspended;
    }

    private static function validHost(string $host): bool
    {
        if ($host === '' || trim($host) !== $host || preg_match('/[\s\/\\\\]/', $host) === 1 || str_contains($host, '://')) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        return preg_match('/^(?=.{1,253}$)(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)(?:\.(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?))*\.?$/D', $host) === 1;
    }
}
