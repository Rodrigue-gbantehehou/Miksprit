<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Voucher;
use App\Entity\Router;

interface ExpiredVoucherRepository
{
    /** @return list<array{voucher: Voucher, router: Router}> */
    public function due(int $limit): array;

    public function unassignedDueCount(): int;

    public function complete(Voucher $voucher): void;
}
