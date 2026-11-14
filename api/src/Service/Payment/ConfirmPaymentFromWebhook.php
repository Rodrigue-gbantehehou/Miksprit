<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Repository\PaymentConfirmationStore;
use App\Event\PaymentWebhookEvent;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(PaymentWebhookConsumer::class)]
final readonly class ConfirmPaymentFromWebhook implements PaymentWebhookConsumer
{
    public function __construct(
        private PaymentProviderRegistry $providers,
        private PaymentConfirmationStore $confirmationStore,
    ) {
    }

    public function consume(PaymentWebhookEvent $event): void
    {
        if (!str_starts_with($event->eventType, 'transaction.')) {
            return;
        }

        $verifiedStatus = $this->providers->verify($event->provider, $event->providerReference);
        if ($verifiedStatus->provider !== $event->provider || $verifiedStatus->providerReference !== $event->providerReference) {
            throw new \DomainException('Verified PSP transaction does not match the webhook reference.');
        }

        $this->confirmationStore->apply($verifiedStatus);
    }
}
