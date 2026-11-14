<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PaymentAttempt;
use App\Entity\PlanSnapshot;
use App\Entity\WifiOrder;

interface PublicCheckoutRepository
{
    public function findPurchaseablePlan(string $siteId, string $planId): ?PlanSnapshot;

    public function findAttemptByCheckoutKeyHash(string $keyHash): ?PaymentAttempt;

    public function saveOrderAndAttempt(WifiOrder $order, PaymentAttempt $attempt): void;

    public function saveAttempt(PaymentAttempt $attempt): void;

    /** @return array{reference: string, paymentStatus: string, orderStatus: string, tenantId: ?string, voucherId: ?string, voucherStatus: ?string, encryptedCredential: ?string}|null */
    public function findPublicOrderStatus(string $reference): ?array;
}
