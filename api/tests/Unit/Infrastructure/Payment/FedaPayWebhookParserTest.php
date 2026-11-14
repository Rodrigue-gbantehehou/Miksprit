<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Payment;

use App\Service\Provider\Payment\FedaPayWebhookParser;
use PHPUnit\Framework\TestCase;

final class FedaPayWebhookParserTest extends TestCase
{
    public function testProviderTransactionIdIsNotConfusedWithMerchantReference(): void
    {
        $event = FedaPayWebhookParser::parse('{"id":12,"type":"transaction.approved","object_id":987,"data":{"reference":"merchant_order_42"}}');

        self::assertSame('12', $event->eventId);
        self::assertSame('transaction.approved', $event->eventType);
        self::assertSame('987', $event->providerReference);
    }

    public function testRejectsWebhookWithoutProviderTransactionId(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        FedaPayWebhookParser::parse('{"id":"evt_1","type":"transaction.approved","data":{"reference":"merchant_order_42"}}');
    }
}
