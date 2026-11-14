<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\HotspotSession;
use App\Entity\HotspotSessionStatus;
use App\Entity\Router;
use App\Entity\RouterStatus;
use App\Entity\Site;
use App\Entity\SiteStatus;
use App\Entity\TenantId;
use App\Entity\Voucher;
use App\Entity\VoucherStatus;
use PHPUnit\Framework\TestCase;

final class AccessInfrastructureEntitiesTest extends TestCase
{
    public function testSiteStartsAsDraftAndActivatesExplicitly(): void
    {
        $site = new Site('site_0001', new TenantId('tenant_001'), 'Centre', 'centre', hash('sha256', 'opaque-token'));

        self::assertSame(SiteStatus::Draft, $site->status());
        $site->activate();
        self::assertSame(SiteStatus::Active, $site->status());
    }

    public function testRouterIsNotActiveUntilVerifiedReachability(): void
    {
        $router = new Router('router_001', new TenantId('tenant_001'), 'site_0001', 'RB5009', 'router.internal', 8729, 'secret-ref/router_001');

        self::assertSame(RouterStatus::PendingEnrollment, $router->status());
        $router->markReachable('7.16.2', new \DateTimeImmutable());
        self::assertSame(RouterStatus::Active, $router->status());
    }

    public function testVoucherAndSessionRequireExternalProvisioningConfirmation(): void
    {
        $voucher = new Voucher('voucher_1', new TenantId('tenant_001'), 'site_0001', hash('sha256', 'voucher-code'));
        self::assertSame(VoucherStatus::Reserved, $voucher->status());
        $voucher->markProvisioned('ciphertext-from-kms', new \DateTimeImmutable('+1 hour'));
        self::assertSame(VoucherStatus::Active, $voucher->status());

        $session = new HotspotSession('session_1', new TenantId('tenant_001'), 'router_001', 'voucher_1', 'ros-session-1');
        self::assertSame(HotspotSessionStatus::Queued, $session->status());
        $session->confirmStarted(new \DateTimeImmutable());
        self::assertSame(HotspotSessionStatus::Active, $session->status());
        $session->recordAccounting(null, null);
    }

    public function testVoucherCannotBeActivatedWithoutFutureExpiry(): void
    {
        $voucher = new Voucher('voucher_1', new TenantId('tenant_001'), 'site_0001', hash('sha256', 'voucher-code'));

        $this->expectException(\DomainException::class);
        $voucher->markProvisioned('ciphertext-from-kms', new \DateTimeImmutable('-1 minute'));
    }
}
