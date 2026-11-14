<?php

declare(strict_types=1);

namespace App\Service\Payment;

use Doctrine\DBAL\Connection;

/** Rechecks approved transactions until FedaPay reports actual settlement and fees. */
final readonly class RefreshOperatorSettlements
{
    public function __construct(private Connection $connection, private PaymentProviderRegistry $providers) {}

    public function execute(int $limit = 100): array
    {
        $rows = $this->connection->fetchAllAssociative("SELECT s.id, s.tenant_id, s.order_reference, s.gross_minor_units, s.commission_bps, p.provider, p.provider_reference FROM operator_settlements s JOIN payment_attempts p ON p.tenant_id=s.tenant_id AND p.order_reference=s.order_reference WHERE s.state='awaiting_settlement' ORDER BY s.created_at LIMIT ?", [$limit]);
        $settled = 0; $pending = 0; $failed = 0;
        foreach ($rows as $row) {
            try {
                $status = $this->providers->verify((string) $row['provider'], (string) $row['provider_reference']);
                if ($status->merchantReference !== $row['order_reference'] || $status->amount->minorUnits !== (int) $row['gross_minor_units'] || !$status->settled
                    || $status->providerFeeMinorUnits === null || $status->amountTransferredMinorUnits === null) { ++$pending; continue; }
                $commission = intdiv((int) $row['gross_minor_units'] * (int) $row['commission_bps'], 10_000);
                $net = (int) $row['gross_minor_units'] - $status->providerFeeMinorUnits - $commission;
                if ($net < 0 || $status->amountTransferredMinorUnits > (int) $row['gross_minor_units']
                    || $status->providerFeeMinorUnits > (int) $row['gross_minor_units']
                    || $net > $status->amountTransferredMinorUnits) {
                    $this->connection->update('operator_settlements', [
                        'provider_fee_minor_units' => $status->providerFeeMinorUnits,
                        'amount_transferred_minor_units' => $status->amountTransferredMinorUnits,
                        'commission_minor_units' => $commission,
                        'state' => 'needs_review',
                        'updated_at' => $status->observedAt->format('Y-m-d H:i:s'),
                    ], ['id' => $row['id'], 'state' => 'awaiting_settlement']);
                    ++$failed;
                    continue;
                }
                $this->connection->transactional(function (Connection $connection) use ($row, $status, $commission, $net): void {
                    $settlement = $connection->fetchAssociative('SELECT state FROM operator_settlements WHERE id = ? FOR UPDATE', [$row['id']]);
                    if (!is_array($settlement) || $settlement['state'] !== 'awaiting_settlement') return;
                    $now = $status->observedAt->format('Y-m-d H:i:s');
                    $connection->update('operator_settlements', ['provider_fee_minor_units' => $status->providerFeeMinorUnits,
                        'amount_transferred_minor_units' => $status->amountTransferredMinorUnits, 'commission_minor_units' => $commission,
                        'operator_net_minor_units' => $net, 'state' => 'available', 'updated_at' => $now], ['id' => $row['id']]);
                    $connection->executeStatement('INSERT IGNORE INTO operator_wallet_entries (id, tenant_id, source_type, source_reference, source_reference_hash, entry_type, amount_delta_minor, currency, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        ['wallet_'.substr(hash('sha256', $row['tenant_id']."\0".$row['order_reference']."\0sale_credit"), 0, 40), $row['tenant_id'], 'wifi_order', $row['order_reference'], hash('sha256', (string) $row['order_reference']), 'sale_credit', $net, 'XOF', $now]);
                });
                ++$settled;
            } catch (\Throwable) { ++$failed; }
        }
        return ['settled' => $settled, 'pending' => $pending, 'failed' => $failed];
    }
}
