<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Orders;

use App\Entity\PlanSnapshot;
use App\Entity\OrderState;
use App\Entity\WifiOrder;
use App\Entity\PaymentAttempt;
use App\Entity\PaymentState;
use App\Entity\VerifiedPaymentStatus;
use App\Entity\XofAmount;
use App\Entity\TenantId;
use PHPUnit\Framework\TestCase;

final class WifiOrderTest extends TestCase
{
    public function testAccessCannotBeProvisionedBeforeMatchingVerifiedPayment(): void
    {
        $tenantId = new TenantId('tenant_1');
        $order = $this->order($tenantId);
        $attempt = new PaymentAttempt('payment_1', $tenantId, 'order_public_1', 'fedapay', new XofAmount(500));
        $attempt->markPending('transaction_1');

        $this->expectException(\DomainException::class);
        $order->confirmPayment($attempt);
        $order->markAccessPending();
    }

    public function testVerifiedPaymentIsRequiredAndFulfillmentIsIdempotent(): void
    {
        $tenantId = new TenantId('tenant_1');
        $order = $this->order($tenantId);
        $attempt = new PaymentAttempt('payment_1', $tenantId, 'order_public_1', 'fedapay', new XofAmount(500));
        $attempt->markPending('transaction_1');
        $attempt->applyVerifiedStatus(new VerifiedPaymentStatus('fedapay', 'transaction_1', 'order_public_1', new XofAmount(500), 'XOF', PaymentState::Succeeded, new \DateTimeImmutable()));

        $order->confirmPayment($attempt);
        $order->markAccessPending();
        $order->fulfill('voucher_1');
        $order->fulfill('voucher_1');

        self::assertSame(OrderState::Fulfilled, $order->state());
        self::assertSame('voucher_1', $order->voucherReference());
    }

    public function testSecondSuccessfulPaymentForSameOrderRequiresReconciliation(): void
    {
        $tenantId = new TenantId('tenant_1');
        $order = $this->order($tenantId);
        $first = new PaymentAttempt('payment_1', $tenantId, 'order_public_1', 'fedapay', new XofAmount(500));
        $first->markPending('transaction_1');
        $first->applyVerifiedStatus(new VerifiedPaymentStatus('fedapay', 'transaction_1', 'order_public_1', new XofAmount(500), 'XOF', PaymentState::Succeeded, new \DateTimeImmutable()));
        $order->confirmPayment($first);

        $second = new PaymentAttempt('payment_2', $tenantId, 'order_public_1', 'fedapay', new XofAmount(500));
        $second->markPending('transaction_2');
        $second->applyVerifiedStatus(new VerifiedPaymentStatus('fedapay', 'transaction_2', 'order_public_1', new XofAmount(500), 'XOF', PaymentState::Succeeded, new \DateTimeImmutable()));

        $this->expectException(\DomainException::class);
        $order->confirmPayment($second);
    }

    public function testVerifiedFailureCanCloseOnlyAnUnpaidOrder(): void
    {
        $tenantId = new TenantId('tenant_1');
        $order = $this->order($tenantId);
        $attempt = new PaymentAttempt('payment_1', $tenantId, 'order_public_1', 'fedapay', new XofAmount(500));
        $attempt->markPending('transaction_1');
        $attempt->applyVerifiedStatus(new VerifiedPaymentStatus('fedapay', 'transaction_1', 'order_public_1', new XofAmount(500), 'XOF', PaymentState::Failed, new \DateTimeImmutable()));

        $order->markPaymentFailed($attempt);

        self::assertSame(OrderState::PaymentFailed, $order->state());
    }

    private function order(TenantId $tenantId): WifiOrder
    {
        $snapshot = new PlanSnapshot('plan_1', 'site_1', $tenantId, 1, '1 heure', new XofAmount(500), 3600, null, 1);

        return new WifiOrder('order_1', 'order_public_1', $tenantId, $snapshot, new \DateTimeImmutable());
    }
}
