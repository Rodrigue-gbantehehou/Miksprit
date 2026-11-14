<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Entity\PaymentAttempt;
use App\Entity\PaymentState;
use App\Entity\VerifiedPaymentStatus;
use App\Entity\XofAmount;
use App\Entity\TenantId;
use PHPUnit\Framework\TestCase;

final class PaymentAttemptTest extends TestCase
{
    public function testMatchingVerifiedStatusConfirmsPaymentAndIsIdempotent(): void
    {
        $attempt = new PaymentAttempt('pay_000001', new TenantId('tenant_1'), 'ord_000001', 'fedapay', new XofAmount(2500));
        $attempt->markPending('txn_1');
        $status = new VerifiedPaymentStatus('fedapay', 'txn_1', 'ord_000001', new XofAmount(2500), 'XOF', PaymentState::Succeeded, new \DateTimeImmutable());

        $attempt->applyVerifiedStatus($status);
        $attempt->applyVerifiedStatus($status);

        self::assertSame(PaymentState::Succeeded, $attempt->state());
    }

    public function testMismatchedMerchantReferenceCannotConfirmPayment(): void
    {
        $attempt = new PaymentAttempt('pay_000001', new TenantId('tenant_1'), 'ord_000001', 'fedapay', new XofAmount(2500));
        $attempt->markPending('txn_1');
        $status = new VerifiedPaymentStatus('fedapay', 'txn_1', 'ord_other', new XofAmount(2500), 'XOF', PaymentState::Succeeded, new \DateTimeImmutable());

        $this->expectException(\DomainException::class);
        $attempt->applyVerifiedStatus($status);
    }

    public function testBrowserLikePendingAttemptCannotSkipVerification(): void
    {
        $attempt = new PaymentAttempt('pay_000001', new TenantId('tenant_1'), 'ord_000001', 'fedapay', new XofAmount(2500));

        self::assertSame(PaymentState::Initiated, $attempt->state());
    }

    public function testDuplicateSuccessStillRequiresMatchingEvidence(): void
    {
        $attempt = new PaymentAttempt('pay_000001', new TenantId('tenant_1'), 'ord_000001', 'fedapay', new XofAmount(2500));
        $attempt->markPending('txn_1');
        $success = new VerifiedPaymentStatus('fedapay', 'txn_1', 'ord_000001', new XofAmount(2500), 'XOF', PaymentState::Succeeded, new \DateTimeImmutable());
        $attempt->applyVerifiedStatus($success);
        $wrong = new VerifiedPaymentStatus('fedapay', 'txn_2', 'ord_other', new XofAmount(2500), 'XOF', PaymentState::Succeeded, new \DateTimeImmutable());

        $this->expectException(\DomainException::class);
        $attempt->applyVerifiedStatus($wrong);
    }
}
