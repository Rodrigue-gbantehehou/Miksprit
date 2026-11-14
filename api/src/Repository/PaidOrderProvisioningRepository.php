<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PaymentOutboxMessage;
use App\Entity\Voucher;
use App\Entity\WifiOrder;
use App\Service\Payment\PaidOrderProvisioningContext;

interface PaidOrderProvisioningRepository
{
    public function claimNext(): ?PaymentOutboxMessage;
    public function findContext(PaymentOutboxMessage $message): ?PaidOrderProvisioningContext;
    public function findVoucher(PaymentOutboxMessage $message): ?Voucher;
    public function saveVoucher(Voucher $voucher): void;
    public function complete(PaymentOutboxMessage $message, Voucher $voucher, WifiOrder $order): void;
    public function fail(PaymentOutboxMessage $message): void;
    public function release(PaymentOutboxMessage $message): void;
}
