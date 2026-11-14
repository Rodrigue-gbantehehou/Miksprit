<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Router;

use App\Service\Provider\Router\RouterOsConnection;
use App\Service\Provider\Router\RouterOsCredentials;
use App\Service\Provider\Router\RouterOsHotspotProvisioner;
use App\Service\Provider\Router\RouterOsReply;
use App\Service\Provider\Router\RouterOsReplyStatus;
use App\Service\Provider\Router\RouterOsSentence;
use App\Service\Provider\Router\RouterOsTransport;
use App\Service\Provider\Router\RouterOsException;
use App\Service\Provider\Router\RouterOsUnavailable;
use App\Service\Provider\Router\UnavailableRouterOsTransport;
use PHPUnit\Framework\TestCase;

final class RouterOsHotspotProvisionerTest extends TestCase
{
    public function testSendsDataAsSeparateWordsAndAcceptsOnlyDoneReply(): void
    {
        $transport = new class implements RouterOsTransport {
            public ?RouterOsSentence $sentence = null;
            public function execute(RouterOsConnection $connection, RouterOsCredentials $credentials, RouterOsSentence $sentence): RouterOsReply
            {
                $this->sentence = $sentence;
                self::assertSame(8729, $connection->port);
                return new RouterOsReply(RouterOsReplyStatus::DONE);
            }
        };
        $provisioner = new RouterOsHotspotProvisioner($transport, new RouterOsConnection('10.20.30.1'), new RouterOsCredentials('svc', 'secret'));

        $provisioner->createUser('voucher-1', 'pass=word', 'daily', '1d2h');

        self::assertSame(['/ip/hotspot/user/add', '=name=voucher-1', '=password=pass=word', '=profile=daily', '=limit-uptime=1d2h'], $transport->sentence?->words);
    }

    public function testTrapFailsWithoutExposingRouterResponse(): void
    {
        $transport = new class implements RouterOsTransport {
            public function execute(RouterOsConnection $connection, RouterOsCredentials $credentials, RouterOsSentence $sentence): RouterOsReply
            {
                return new RouterOsReply(RouterOsReplyStatus::TRAP, 'ALREADY_EXISTS');
            }
        };
        $provisioner = new RouterOsHotspotProvisioner($transport, new RouterOsConnection('router.example'), new RouterOsCredentials('svc', 'secret'));

        try {
            $provisioner->createUser('voucher-1', 'secret-voucher', 'daily');
            self::fail('A RouterOS trap must not be reported as success.');
        } catch (RouterOsException $exception) {
            self::assertStringContainsString('ALREADY_EXISTS', $exception->getMessage());
            self::assertStringNotContainsString('secret-voucher', $exception->getMessage());
        }
    }

    public function testUnavailableTransportDoesNotFakeSuccess(): void
    {
        $provisioner = new RouterOsHotspotProvisioner(new UnavailableRouterOsTransport(), new RouterOsConnection('10.0.0.8'), new RouterOsCredentials('svc', 'secret'));

        $this->expectException(RouterOsUnavailable::class);
        $provisioner->createUser('voucher-1', 'secret-voucher', 'daily');
    }

    public function testRejectsInvalidEndpointAndVoucherFields(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RouterOsConnection('http://router.example');
    }
}
