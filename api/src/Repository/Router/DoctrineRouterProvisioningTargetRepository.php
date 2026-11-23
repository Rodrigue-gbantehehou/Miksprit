<?php

declare(strict_types=1);

namespace App\Repository\Router;

use App\Entity\Router;
use App\Entity\TenantId;
use App\Service\Provider\Router\RouterOsConnection;
use App\Service\Router\RouterProvisioningTarget;
use App\Service\Router\RouterProvisioningTargetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsAlias(RouterProvisioningTargetRepository::class)]
final readonly class DoctrineRouterProvisioningTargetRepository implements RouterProvisioningTargetRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        #[Autowire('%env(default:empty_secret:ROUTER_ALLOWED_HOSTS)%')]
        private string $allowedHosts,
    )
    {
    }

    public function findTargetForTenant(TenantId $tenantId, string $routerId): ?RouterProvisioningTarget
    {
        if (preg_match('/^[A-Za-z0-9_-]{8,80}$/D', $routerId) !== 1) {
            return null;
        }
        $row = $this->entityManager->createQueryBuilder()
            ->select('router.host AS host', 'router.apiPort AS port', 'router.credentialReference AS credentialReference', 'router.providerCode AS providerCode')
            ->from(Router::class, 'router')
            ->where('router.id = :routerId')
            ->andWhere('router.ownerTenantId.value = :tenantId')
            ->setParameter('routerId', $routerId)
            ->setParameter('tenantId', $tenantId->value)
            ->getQuery()
            ->getOneOrNullResult();
        if (!is_array($row)) {
            return null;
        }

        $host = strtolower(rtrim((string) $row['host'], '.'));
        $allowlist = array_filter(array_map(static fn (string $value): string => strtolower(rtrim(trim($value), '.')), explode(',', $this->allowedHosts)));
        if ($allowlist === [] || !in_array($host, $allowlist, true)) {
            throw new \DomainException('Router endpoint is not in the deployment allowlist.');
        }

        return new RouterProvisioningTarget(
            new RouterOsConnection($host, (int) $row['port']),
            (string) $row['credentialReference'],
            (string) $row['providerCode'],
        );
    }
}
