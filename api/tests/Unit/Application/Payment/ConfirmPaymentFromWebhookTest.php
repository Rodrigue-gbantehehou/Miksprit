<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Payment;

use App\Service\Payment\ConfirmPaymentFromWebhook;
use App\Repository\PaymentConfirmationStore;
use App\Service\Payment\PaymentStatusVerifier;
use App\Event\PaymentWebhookEvent;
use App\Entity\PaymentState;
use App\Entity\VerifiedPaymentStatus;
use App\Entity\XofAmount;
use PHPUnit\Framework\TestCase;

final class ConfirmPaymentFromWebhookTest extends TestCase
{
    public function testWebhookOnlyTriggersServerVerificationAndStoresVerifiedEvidence(): void
    {
        $evidence = new VerifiedPaymentStatus('fedapay', 'transaction_42', 'order_42', new XofAmount(3500), 'XOF', PaymentState::Succeeded, new \DateTimeImmutable());
        $verifier = new RecordingPaymentStatusVerifier($evidence);
        $store = new RecordingPaymentConfirmationStore();
        $consumer = new ConfirmPaymentFromWebhook($verifier, $store);

        $consumer->consume(new PaymentWebhookEvent('evt_42', 'transaction.approved', 'transaction_42'));

        self::assertSame(['fedapay', 'transaction_42'], $verifier->lastLookup);
        self::assertSame($evidence, $store->status);
    }

    public function testWebhookReferenceMustMatchServerVerifiedReference(): void
    {
        $evidence = new VerifiedPaymentStatus('fedapay', 'transaction_other', 'order_42', new XofAmount(3500), 'XOF', PaymentState::Succeeded, new \DateTimeImmutable());
        $store = new RecordingPaymentConfirmationStore();
        $consumer = new ConfirmPaymentFromWebhook(new RecordingPaymentStatusVerifier($evidence), $store);

        $this->expectException(\DomainException::class);
        $consumer->consume(new PaymentWebhookEvent('evt_42', 'transaction.approved', 'transaction_42'));
    }
}

final class RecordingPaymentStatusVerifier implements PaymentStatusVerifier
{
    /** @var array{string, string}|null */
    public ?array $lastLookup = null;

    public function __construct(private readonly VerifiedPaymentStatus $status)
    {
    }

    public function verify(string $provider, string $providerReference): VerifiedPaymentStatus
    {
        $this->lastLookup = [$provider, $providerReference];

        return $this->status;
    }
}

final class RecordingPaymentConfirmationStore implements PaymentConfirmationStore
{
    public ?VerifiedPaymentStatus $status = null;

    public function apply(VerifiedPaymentStatus $status): void
    {
        $this->status = $status;
    }
}
