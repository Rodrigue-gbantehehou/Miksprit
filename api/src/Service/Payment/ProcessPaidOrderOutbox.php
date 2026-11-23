<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Entity\PaymentOutboxMessage;
use App\Entity\Voucher;
use App\Entity\VoucherStatus;
use App\Repository\PaidOrderProvisioningRepository;
use App\Service\Provider\Router\RouterOsCommandRejected;
use App\Service\Provider\Router\RouterOsUnavailable;
use App\Service\Router\HotspotProvisionerFactory;
use App\Service\Router\HotspotProvisioningUnavailable;
use App\Service\Router\VoucherCredentialCipher;

/** Processes one durable paid-order signal and provisions a matching RouterOS account. */
final readonly class ProcessPaidOrderOutbox
{
    public function __construct(
        private PaidOrderProvisioningRepository $repository,
        private HotspotProvisionerFactory $routers,
        private VoucherCredentialCipher $cipher,
        private PaidOrderProvisioningSettings $settings,
    ) {
    }

    /** @return 'idle'|'completed'|'failed'|'retry'|'ambiguous' */
    public function processOne(): string
    {
        $this->cipher->assertConfigured();
        $message = $this->repository->claimNext();
        if (!$message instanceof PaymentOutboxMessage) return 'idle';
        $context = $this->repository->findContext($message);
        if ($context === null) {
            $this->repository->fail($message);
            return 'failed';
        }
        $order = $context->order;
        $voucher = $this->repository->findVoucher($message);
        if ($voucher === null) {
            $voucherId = bin2hex(random_bytes(16));
            $voucher = new Voucher($voucherId, $message->tenantId, $order->plan->siteId, hash('sha256', $voucherId), $order->publicReference, routerId: $context->router->id);
            $this->repository->saveVoucher($voucher);
        }
        if ($voucher->status() !== VoucherStatus::Reserved) {
            $this->repository->fail($message);
            return 'failed';
        }

        try {
            $envelope = $voucher->preparedCredential();
            if ($envelope === null) {
                $username = 'w'.substr($voucher->id, 0, 15);
                $password = bin2hex(random_bytes(16));
                $envelope = $this->cipher->encrypt($message->tenantId, $voucher->id, $username, $password);
                $voucher->prepareCredential($envelope);
                $this->repository->saveVoucher($voucher);
            } else {
                $credentials = $this->cipher->decrypt($message->tenantId, $voucher->id, $envelope);
                $username = $credentials['username'];
                $password = $credentials['password'];
            }
        } catch (\Throwable) {
            $this->repository->fail($message);
            return 'failed';
        }
        try {
            $port = $this->routers->forRouter($context->router);
        } catch (RouterOsUnavailable|HotspotProvisioningUnavailable) {
            $this->repository->release($message);
            return 'retry';
        } catch (\Throwable) {
            // The command may have reached RouterOS. Keep this message claimed to prevent a duplicate user.
            return 'ambiguous';
        }
        try {
            $port->createUser($username, $password, $this->settings->hotspotProfile,
                self::routerDuration($order->plan->sessionSeconds), $order->plan->dataLimitBytes, $order->plan->deviceLimit);
        } catch (RouterOsCommandRejected) {
            $this->repository->fail($message);
            return 'failed';
        } catch (RouterOsUnavailable|HotspotProvisioningUnavailable) {
            $this->repository->release($message);
            return 'retry';
        } catch (\Throwable) {
            // The command may have reached RouterOS. Keep this message claimed to prevent a duplicate user.
            return 'ambiguous';
        }

        try {
            $expiresAt = new \DateTimeImmutable('+'.$order->plan->sessionSeconds.' seconds');
            $voucher->markProvisioned($envelope, $expiresAt, $username);
            $order->fulfill($voucher->id);
            $this->repository->complete($message, $voucher, $order);
        } catch (\Throwable) {
            // Router accepted the user but persistence may have failed; leave the durable claim for reconciliation.
            return 'ambiguous';
        }
        return 'completed';
    }

    private static function routerDuration(int $seconds): string
    {
        $units = ['w' => 604800, 'd' => 86400, 'h' => 3600, 'm' => 60, 's' => 1];
        $remaining = $seconds;
        $duration = '';
        foreach ($units as $unit => $size) {
            $count = intdiv($remaining, $size);
            if ($count > 0) {
                $duration .= $count.$unit;
                $remaining %= $size;
            }
        }
        return $duration;
    }
}
