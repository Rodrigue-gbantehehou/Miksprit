<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Router;

use App\Entity\Membership;
use App\Entity\OrderState;
use App\Entity\PaymentAttempt;
use App\Entity\PaymentState;
use App\Entity\PlanSnapshot;
use App\Entity\Router;
use App\Entity\RouterStatus;
use App\Entity\Site;
use App\Entity\TenantContext;
use App\Entity\TenantId;
use App\Entity\VerifiedPaymentStatus;
use App\Entity\Voucher;
use App\Entity\VoucherStatus;
use App\Entity\WifiOrder;
use App\Entity\XofAmount;
use App\Service\Provider\Router\RouterOsCommandRejected;
use App\Service\Router\HotspotProvisionerFactory;
use App\Service\Router\HotspotProvisioningPort;
use App\Service\Router\ProvisionVoucher;
use App\Service\Router\VoucherCredentialCipher;
use App\Service\Router\VoucherProvisioningIdempotencyStore;
use App\Service\Router\VoucherProvisioningRepository;
use App\Service\Router\VoucherProvisioningRequest;
use PHPUnit\Framework\TestCase;

final class ProvisionVoucherTest extends TestCase
{
    public function testVoucherBecomesActiveOnlyAfterRouterConfirmationAndPersistsCiphertext(): void
    {
        $tenantId = new TenantId('tenant_01');
        $router = new Router('router_01', $tenantId, 'site_001', 'Router A', '10.0.0.2', 8729, 'secret://router_01');
        $router->markReachable('7.15.3', new \DateTimeImmutable());
        $order = $this->accessPendingOrder($tenantId);
        $voucher = new Voucher('voucher_01', $tenantId, 'site_001', hash('sha256', 'voucher-code'), $order->publicReference);
        $repository = new RecordingVoucherProvisioningRepository($router, $voucher, $order);
        $gateway = new RecordingHotspotProvisioner();
        $idempotency = new RecordingProvisioningIdempotencyStore();
        $cipher = new RecordingVoucherCredentialCipher();
        $service = new ProvisionVoucher($repository, new RecordingHotspotProvisionerFactory($gateway), $cipher, $idempotency);

        $result = $service->execute($this->context($tenantId), $this->request());

        self::assertSame(VoucherStatus::Active, $result->status);
        self::assertSame(VoucherStatus::Active, $voucher->status());
        self::assertSame(OrderState::Fulfilled, $order->state());
        self::assertSame($voucher->id, $order->voucherReference());
        self::assertSame('encrypted:username:clear-password', $cipher->lastCiphertext);
        self::assertSame('encrypted:username:clear-password', $repository->savedCredential());
        self::assertSame('clear-password', $gateway->lastPassword);
        self::assertSame(['claim', 'complete'], $idempotency->events);
    }

    public function testRouterFailureDoesNotActivateVoucherOrPersistSecret(): void
    {
        $tenantId = new TenantId('tenant_01');
        $router = new Router('router_01', $tenantId, 'site_001', 'Router A', '10.0.0.2', 8729, 'secret://router_01');
        $router->markReachable('7.15.3', new \DateTimeImmutable());
        $order = $this->accessPendingOrder($tenantId);
        $voucher = new Voucher('voucher_01', $tenantId, 'site_001', hash('sha256', 'voucher-code'), $order->publicReference);
        $repository = new RecordingVoucherProvisioningRepository($router, $voucher, $order);
        $gateway = new RecordingHotspotProvisioner(true);
        $idempotency = new RecordingProvisioningIdempotencyStore();
        $cipher = new RecordingVoucherCredentialCipher();
        $service = new ProvisionVoucher($repository, new RecordingHotspotProvisionerFactory($gateway), $cipher, $idempotency);

        try {
            $service->execute($this->context($tenantId), $this->request());
            self::fail('Expected router provisioning to fail.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('confirmed', $exception->getMessage());
        }

        self::assertSame(VoucherStatus::Reserved, $voucher->status());
        self::assertSame(OrderState::AccessPending, $order->state());
        self::assertNull($cipher->lastCiphertext);
        self::assertNull($repository->saved);
        self::assertSame(['claim', 'release'], $idempotency->events);
    }

    public function testCrossTenantRouterOrVoucherCannotProvision(): void
    {
        $tenantId = new TenantId('tenant_01');
        $foreignTenant = new TenantId('tenant_02');
        $router = new Router('router_01', $foreignTenant, 'site_001', 'Router A', '10.0.0.2', 8729, 'secret://router_01');
        $router->markReachable('7.15.3', new \DateTimeImmutable());
        $order = $this->accessPendingOrder($tenantId);
        $voucher = new Voucher('voucher_01', $tenantId, 'site_001', hash('sha256', 'voucher-code'), $order->publicReference);
        $repository = new RecordingVoucherProvisioningRepository($router, $voucher, $order);
        $gateway = new RecordingHotspotProvisioner();
        $service = new ProvisionVoucher($repository, new RecordingHotspotProvisionerFactory($gateway), new RecordingVoucherCredentialCipher(), new RecordingProvisioningIdempotencyStore());

        $this->expectException(\DomainException::class);
        $service->execute($this->context($tenantId), $this->request());
    }

    public function testInactiveSiteCannotCallRouter(): void
    {
        $tenantId = new TenantId('tenant_01');
        $router = new Router('router_01', $tenantId, 'site_001', 'Router A', '10.0.0.2', 8729, 'secret://router_01');
        $router->markReachable('7.15.3', new \DateTimeImmutable());
        $order = $this->accessPendingOrder($tenantId);
        $voucher = new Voucher('voucher_01', $tenantId, 'site_001', hash('sha256', 'voucher-code'), $order->publicReference);
        $site = new Site('site_001', $tenantId, 'Site A', 'site-a', hash('sha256', 'site-token'));
        $site->activate();
        $site->suspend();
        $repository = new RecordingVoucherProvisioningRepository($router, $voucher, $order, $site);
        $gateway = new RecordingHotspotProvisioner();
        $idempotency = new RecordingProvisioningIdempotencyStore();
        $service = new ProvisionVoucher($repository, new RecordingHotspotProvisionerFactory($gateway), new RecordingVoucherCredentialCipher(), $idempotency);

        $this->expectException(\DomainException::class);
        try {
            $service->execute($this->context($tenantId), $this->request());
        } finally {
            self::assertNull($gateway->lastPassword);
            self::assertSame([], $idempotency->events);
        }
    }

    public function testUnknownOrForeignTenantSiteCannotCallRouter(): void
    {
        foreach ([null, new Site('site_001', new TenantId('tenant_02'), 'Foreign site', 'foreign-site', hash('sha256', 'foreign-token'))] as $site) {
            $tenantId = new TenantId('tenant_01');
            $router = new Router('router_01', $tenantId, 'site_001', 'Router A', '10.0.0.2', 8729, 'secret://router_01');
            $router->markReachable('7.15.3', new \DateTimeImmutable());
            $order = $this->accessPendingOrder($tenantId);
            $voucher = new Voucher('voucher_01', $tenantId, 'site_001', hash('sha256', 'voucher-code'), $order->publicReference);
            $repository = new RecordingVoucherProvisioningRepository($router, $voucher, $order, $site, $site === null);
            $gateway = new RecordingHotspotProvisioner();
            $service = new ProvisionVoucher($repository, new RecordingHotspotProvisionerFactory($gateway), new RecordingVoucherCredentialCipher(), new RecordingProvisioningIdempotencyStore());

            try {
                $service->execute($this->context($tenantId), $this->request());
                self::fail('Expected an unknown or foreign site to be rejected.');
            } catch (\DomainException) {
                self::assertNull($gateway->lastPassword);
            }
        }
    }

    public function testVoucherWithoutLinkedOrderIsRejected(): void
    {
        $tenantId = new TenantId('tenant_01');
        $router = new Router('router_01', $tenantId, 'site_001', 'Router A', '10.0.0.2', 8729, 'secret://router_01');
        $router->markReachable('7.15.3', new \DateTimeImmutable());
        $voucher = new Voucher('voucher_01', $tenantId, 'site_001', hash('sha256', 'voucher-code'));
        $repository = new RecordingVoucherProvisioningRepository($router, $voucher, null);
        $gateway = new RecordingHotspotProvisioner();
        $service = new ProvisionVoucher($repository, new RecordingHotspotProvisionerFactory($gateway), new RecordingVoucherCredentialCipher(), new RecordingProvisioningIdempotencyStore());

        try {
            $service->execute($this->context($tenantId), $this->request());
            self::fail('Unlinked vouchers have no supported free/admin authorization path.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('paid order', $exception->getMessage());
        }
        self::assertNull($gateway->lastPassword);
    }

    public function testPendingPaymentOrderCannotReachRouter(): void
    {
        $tenantId = new TenantId('tenant_01');
        $router = new Router('router_01', $tenantId, 'site_001', 'Router A', '10.0.0.2', 8729, 'secret://router_01');
        $router->markReachable('7.15.3', new \DateTimeImmutable());
        $order = $this->pendingOrder($tenantId);
        $voucher = new Voucher('voucher_01', $tenantId, 'site_001', hash('sha256', 'voucher-code'), $order->publicReference);
        $gateway = new RecordingHotspotProvisioner();
        $service = new ProvisionVoucher(new RecordingVoucherProvisioningRepository($router, $voucher, $order), new RecordingHotspotProvisionerFactory($gateway), new RecordingVoucherCredentialCipher(), new RecordingProvisioningIdempotencyStore());

        $this->expectException(\DomainException::class);
        $service->execute($this->context($tenantId), $this->request());
    }

    public function testAmbiguousRouterFailureRetainsIdempotencyClaim(): void
    {
        $tenantId = new TenantId('tenant_01');
        $router = new Router('router_01', $tenantId, 'site_001', 'Router A', '10.0.0.2', 8729, 'secret://router_01');
        $router->markReachable('7.15.3', new \DateTimeImmutable());
        $order = $this->accessPendingOrder($tenantId);
        $voucher = new Voucher('voucher_01', $tenantId, 'site_001', hash('sha256', 'voucher-code'), $order->publicReference);
        $repository = new RecordingVoucherProvisioningRepository($router, $voucher, $order);
        $gateway = new RecordingHotspotProvisioner(false, true);
        $idempotency = new RecordingProvisioningIdempotencyStore();
        $service = new ProvisionVoucher($repository, new RecordingHotspotProvisionerFactory($gateway), new RecordingVoucherCredentialCipher(), $idempotency);

        try {
            $service->execute($this->context($tenantId), $this->request());
            self::fail('Expected ambiguous provisioning result.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('outcome is unknown', $exception->getMessage());
        }
        self::assertSame(['claim'], $idempotency->events);
        self::assertSame(VoucherStatus::Reserved, $voucher->status());
    }

    private function context(TenantId $tenantId): TenantContext
    {
        $membership = Membership::owner('member_001', $tenantId, 'actor_001');
        return TenantContext::fromMembership($membership, 'actor_001');
    }

    private function request(): VoucherProvisioningRequest
    {
        return new VoucherProvisioningRequest('router_01', 'voucher_01', 'username', 'clear-password', 'daily', new \DateTimeImmutable('+1 day'), '1d');
    }

    private function accessPendingOrder(TenantId $tenantId): WifiOrder
    {
        $order = $this->pendingOrder($tenantId);
        $attempt = new PaymentAttempt('payment_01', $tenantId, $order->publicReference, 'fedapay', new XofAmount(1000));
        $attempt->markPending('transaction_01');
        $attempt->applyVerifiedStatus(new VerifiedPaymentStatus('fedapay', 'transaction_01', $order->publicReference, new XofAmount(1000), 'XOF', PaymentState::Succeeded, new \DateTimeImmutable()));
        $order->confirmPayment($attempt);
        $order->markAccessPending();

        return $order;
    }

    private function pendingOrder(TenantId $tenantId): WifiOrder
    {
        $snapshot = new PlanSnapshot('plan_001', 'site_001', $tenantId, 1, '1 heure', new XofAmount(1000), 3600, null, 1);

        return new WifiOrder('order_001', 'public_order_001', $tenantId, $snapshot, new \DateTimeImmutable());
    }
}

final class RecordingVoucherProvisioningRepository implements VoucherProvisioningRepository
{
    public ?Voucher $saved = null;
    public ?WifiOrder $savedOrder = null;
    private readonly ?Site $site;

    public function __construct(
        private readonly Router $router,
        private readonly Voucher $voucher,
        private readonly ?WifiOrder $order,
        ?Site $site = null,
        bool $siteMissing = false,
    )
    {
        if ($siteMissing) {
            $this->site = null;
            return;
        }

        $this->site = $site ?? new Site($voucher->siteId, $voucher->tenantId(), 'Site A', 'site-a', hash('sha256', 'site-token'));
        if ($site === null) {
            $this->site->activate();
        }
    }

    public function findRouterForTenant(TenantContext $context, string $routerId): ?Router
    {
        return $routerId === $this->router->id && $context->tenantId->equals($this->router->tenantId()) ? $this->router : null;
    }

    public function findSiteForTenant(TenantContext $context, string $siteId): ?Site
    {
        return $this->site !== null && $this->site->id === $siteId && $context->tenantId->equals($this->site->tenantId())
            ? $this->site
            : null;
    }

    public function findVoucherForTenant(TenantContext $context, string $voucherId): ?Voucher
    {
        return $voucherId === $this->voucher->id && $context->tenantId->equals($this->voucher->tenantId()) ? $this->voucher : null;
    }

    public function findOrderForTenantByPublicReference(TenantContext $context, string $publicReference): ?WifiOrder
    {
        return $this->order !== null && $this->order->publicReference === $publicReference && $context->tenantId->equals($this->order->tenantId()) ? $this->order : null;
    }

    public function completeProvisioning(Voucher $voucher, WifiOrder $order): void
    {
        $this->saved = $voucher;
        $this->savedOrder = $order;
    }

    public function savedCredential(): ?string
    {
        $reflection = new \ReflectionProperty(Voucher::class, 'encryptedCredential');
        return $reflection->getValue($this->saved);
    }
}

final class RecordingHotspotProvisionerFactory implements HotspotProvisionerFactory
{
    public function __construct(private readonly HotspotProvisioningPort $port)
    {
    }

    public function forRouter(Router $router): HotspotProvisioningPort
    {
        return $this->port;
    }
}

final class RecordingHotspotProvisioner implements HotspotProvisioningPort
{
    public ?string $lastPassword = null;

    public function __construct(private readonly bool $fail = false, private readonly bool $ambiguous = false)
    {
    }

    public function createUser(string $username, string $password, string $profile, ?string $limitUptime): void
    {
        $this->lastPassword = $password;
        if ($this->ambiguous) {
            throw new \RuntimeException('socket closed after send');
        }
        if ($this->fail) {
            throw new RouterOsCommandRejected('router rejected request');
        }
    }
}

final class RecordingVoucherCredentialCipher implements VoucherCredentialCipher
{
    public ?string $lastCiphertext = null;

    public function encrypt(string $username, string $password): string
    {
        return $this->lastCiphertext = 'encrypted:'.$username.':'.$password;
    }
}

final class RecordingProvisioningIdempotencyStore implements VoucherProvisioningIdempotencyStore
{
    /** @var list<string> */
    public array $events = [];

    public function claim(string $key): bool
    {
        $this->events[] = 'claim';
        return true;
    }

    public function complete(string $key): void
    {
        $this->events[] = 'complete';
    }

    public function release(string $key): void
    {
        $this->events[] = 'release';
    }
}
