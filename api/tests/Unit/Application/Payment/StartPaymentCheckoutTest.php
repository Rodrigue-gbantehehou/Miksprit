<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Payment;

use App\Service\Payment\CheckoutProvider;
use App\Repository\PaymentAttemptRepository;
use App\Service\Payment\PaymentCheckoutSettings;
use App\Service\Payment\PaymentIntent;
use App\Service\Payment\PaymentSession;
use App\Service\Payment\StartPaymentCheckout;
use App\Entity\PaymentAttempt;
use App\Entity\PaymentState;
use App\Entity\XofAmount;
use App\Entity\Membership;
use App\Entity\TenantContext;
use App\Entity\TenantId;
use PHPUnit\Framework\TestCase;

final class StartPaymentCheckoutTest extends TestCase
{
    public function testCreatesCheckoutFromServerOwnedPaymentAttempt(): void
    {
        $attempt = new PaymentAttempt('pay_01', new TenantId('tenant_1'), 'order_01', 'fedapay', new XofAmount(2500));
        $repository = new RecordingPaymentAttemptRepository($attempt);
        $provider = new RecordingCheckoutProvider();
        $service = new StartPaymentCheckout(
            $repository,
            $provider,
            new PaymentCheckoutSettings('https://portal.example.test/payment/return'),
        );

        $context = TenantContext::fromMembership(Membership::owner('membership_1', new TenantId('tenant_1'), 'actor_1'), 'actor_1');
        $session = $service->execute($context, 'pay_01', 'Wi-Fi 1 heure');

        self::assertSame('provider_tx_01', $session->providerReference);
        self::assertSame(PaymentState::Pending, $attempt->state());
        self::assertSame('provider_tx_01', $provider->lastSession?->providerReference);
        self::assertSame('payment-attempt:pay_01', $provider->lastIdempotencyKey);
        self::assertSame('https://portal.example.test/payment/return', $provider->lastIntent?->returnUrl);
        self::assertSame($attempt, $repository->saved);
    }

    public function testMissingTenantScopedAttemptNeverCallsProvider(): void
    {
        $repository = new RecordingPaymentAttemptRepository(null);
        $provider = new RecordingCheckoutProvider();
        $service = new StartPaymentCheckout(
            $repository,
            $provider,
            new PaymentCheckoutSettings('https://portal.example.test/payment/return'),
        );

        try {
            $context = TenantContext::fromMembership(Membership::owner('membership_2', new TenantId('tenant_2'), 'actor_2'), 'actor_2');
            $service->execute($context, 'pay_01', 'Wi-Fi');
            self::fail('Expected an inaccessible attempt to look like a missing attempt.');
        } catch (\DomainException $exception) {
            self::assertSame('Payment attempt not found.', $exception->getMessage());
            self::assertNull($provider->lastIntent);
        }
    }
}

final class RecordingPaymentAttemptRepository implements PaymentAttemptRepository
{
    public ?PaymentAttempt $saved = null;

    public function __construct(private readonly ?PaymentAttempt $attempt)
    {
    }

    public function findForTenant(TenantContext $context, string $attemptId): ?PaymentAttempt
    {
        if ($this->attempt === null || $this->attempt->id !== $attemptId
            || !$this->attempt->tenantId->equals($context->tenantId)) {
            return null;
        }

        return $this->attempt;
    }

    public function save(PaymentAttempt $attempt): void
    {
        $this->saved = $attempt;
    }
}

final class RecordingCheckoutProvider implements CheckoutProvider
{
    public ?PaymentIntent $lastIntent = null;
    public ?PaymentSession $lastSession = null;
    public ?string $lastIdempotencyKey = null;

    public function createCheckout(PaymentIntent $intent, string $idempotencyKey): PaymentSession
    {
        $this->lastIntent = $intent;
        $this->lastIdempotencyKey = $idempotencyKey;
        $this->lastSession = new PaymentSession('provider_tx_01', 'https://checkout.fedapay.com/session/01');

        return $this->lastSession;
    }
}
