<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Payment;

use App\Service\Provider\Payment\FedaPayConfiguration;
use App\Service\Provider\Payment\IdempotentPaymentWebhookService;
use App\Service\Provider\Payment\InvalidPaymentWebhook;
use App\Repository\PaymentIdempotencyStore;
use App\Service\Payment\PaymentWebhookConsumer;
use App\Event\PaymentWebhookEvent;
use App\Service\Provider\Payment\WebhookSignatureValidator;
use PHPUnit\Framework\TestCase;

final class IdempotentPaymentWebhookServiceTest extends TestCase
{
    public function testInvalidSignatureNeverReachesConsumer(): void
    {
        $store = new InMemoryIdempotencyStore();
        $consumer = new RecordingConsumer();
        $service = $this->service(new FedaPayConfiguration(enabled: true, webhookSecret: 'secret'), $store, $consumer);

        try {
            $service->process('{"id":"evt_1","type":"transaction.approved","data":{"reference":"tx_1"}}', 'bad');
            self::fail('Expected invalid webhook rejection.');
        } catch (InvalidPaymentWebhook) {
            self::assertSame([], $consumer->events);
            self::assertSame([], $store->claimed);
        }
    }

    public function testMissingConfigurationRejectsWebhookAndNeverFakesSuccess(): void
    {
        $consumer = new RecordingConsumer();
        $service = $this->service(new FedaPayConfiguration(), new InMemoryIdempotencyStore(), $consumer);

        try {
            $service->process('{}', hash_hmac('sha256', '{}', 'secret'));
            self::fail('Expected disabled webhook rejection.');
        } catch (InvalidPaymentWebhook) {
            self::assertSame([], $consumer->events);
        }
    }

    public function testValidWebhookIsConsumedOnlyOnce(): void
    {
        $body = '{"id":"evt_1","type":"transaction.approved","object_id":"tx_1","data":{"id":"tx_1","reference":"order_1","status":"approved"}}';
        $store = new InMemoryIdempotencyStore();
        $consumer = new RecordingConsumer();
        $service = $this->service(new FedaPayConfiguration(enabled: true, webhookSecret: 'secret'), $store, $consumer);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, 'secret');

        self::assertTrue($service->process($body, 't='.$timestamp.',s='.$signature));
        self::assertFalse($service->process($body, 't='.$timestamp.',s='.$signature));
        self::assertCount(1, $consumer->events);
        self::assertSame('tx_1', $consumer->events[0]->providerReference);
        self::assertSame(['fedapay:evt_1'], $store->completed);
    }

    public function testFedaPaySignatureCanContainMultipleRotationSignatures(): void
    {
        $body = '{"id":"evt_2","type":"transaction.updated","object_id":"tx_2"}';
        $store = new InMemoryIdempotencyStore();
        $consumer = new RecordingConsumer();
        $service = $this->service(new FedaPayConfiguration(enabled: true, webhookSecret: 'secret'), $store, $consumer);
        $timestamp = time();
        $valid = hash_hmac('sha256', $timestamp.'.'.$body, 'secret');

        self::assertTrue($service->process($body, 't='.$timestamp.',s='.str_repeat('0', 64).',s='.$valid));
        self::assertCount(1, $consumer->events);
    }

    public function testExpiredSignatureAndOversizedPayloadAreRejectedBeforeClaim(): void
    {
        $store = new InMemoryIdempotencyStore();
        $consumer = new RecordingConsumer();
        $service = $this->service(new FedaPayConfiguration(enabled: true, webhookSecret: 'secret'), $store, $consumer);
        $body = '{"id":"evt_3","type":"transaction.approved","object_id":"tx_3"}';
        $timestamp = time() - 301;
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, 'secret');

        try {
            $service->process($body, 't='.$timestamp.',s='.$signature);
            self::fail('Expected an expired signature to be rejected.');
        } catch (InvalidPaymentWebhook) {
            self::assertSame([], $store->claimed);
        }

        try {
            $service->process(str_repeat('x', 65_537), 't='.time().',s='.str_repeat('0', 64));
            self::fail('Expected an oversized webhook to be rejected.');
        } catch (InvalidPaymentWebhook) {
            self::assertSame([], $store->claimed);
            self::assertSame([], $consumer->events);
        }
    }

    private function service(
        FedaPayConfiguration $configuration,
        PaymentIdempotencyStore $store,
        RecordingConsumer $consumer,
    ): IdempotentPaymentWebhookService {
        return new IdempotentPaymentWebhookService(
            new WebhookSignatureValidator($configuration),
            $store,
            $consumer,
        );
    }
}

final class InMemoryIdempotencyStore implements PaymentIdempotencyStore
{
    /** @var list<string> */
    public array $claimed = [];
    /** @var list<string> */
    public array $completed = [];

    public function claim(string $key): bool
    {
        if (in_array($key, $this->claimed, true)) {
            return false;
        }
        $this->claimed[] = $key;

        return true;
    }

    public function complete(string $key): void
    {
        $this->completed[] = $key;
    }

    public function release(string $key): void
    {
        $this->claimed = array_values(array_filter($this->claimed, static fn (string $claimed): bool => $claimed !== $key));
    }
}

final class RecordingConsumer implements PaymentWebhookConsumer
{
    /** @var list<PaymentWebhookEvent> */
    public array $events = [];

    public function consume(PaymentWebhookEvent $event): void
    {
        $this->events[] = $event;
    }
}
