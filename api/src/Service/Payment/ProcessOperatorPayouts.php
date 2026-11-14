<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\Service\Provider\Payment\FedaPayGateway;
use Doctrine\DBAL\Connection;

/** Durable payout processor; uncertain provider outcomes remain reserved and are never resubmitted. */
final readonly class ProcessOperatorPayouts
{
    public function __construct(private Connection $connection, private FedaPayGateway $fedapay) {}

    public function processOne(): string
    {
        $row = $this->connection->transactional(function (Connection $connection): array|false {
            $staleProcessingBefore = (new \DateTimeImmutable('-5 minutes'))->format('Y-m-d H:i:s');
            $row = $connection->fetchAssociative("SELECT * FROM operator_payout_requests WHERE state = 'requested' OR (state IN ('pending','ambiguous','processing') AND updated_at < ?) ORDER BY created_at LIMIT 1 FOR UPDATE", [$staleProcessingBefore]);
            if (!is_array($row)) return false;
            $connection->executeStatement("UPDATE operator_payout_requests SET state = 'processing', updated_at = ? WHERE id = ?", [(new \DateTimeImmutable())->format('Y-m-d H:i:s'), $row['id']]);
            return $row;
        });
        if (!is_array($row)) return 'idle';
        try {
            $reference = $row['provider_reference'] === null ? null : (string) $row['provider_reference'];
            if ($row['state'] === 'requested' && $reference === null) {
                $reference = $this->fedapay->createPayoutDraft((string) $row['beneficiary_name'], (string) $row['requester_email'], (int) $row['amount_minor_units']);
                $this->connection->update('operator_payout_requests', ['provider_reference' => $reference, 'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')], ['id' => $row['id']]);
                $this->fedapay->submitPayout($reference, (string) $row['beneficiary_phone']);
            } elseif ($reference === null) {
                $this->connection->executeStatement("UPDATE operator_payout_requests SET state = 'ambiguous', updated_at = ? WHERE id = ? AND state <> 'sent'", [(new \DateTimeImmutable())->format('Y-m-d H:i:s'), $row['id']]);
                return 'ambiguous';
            }
            $payout = $this->fedapay->getPayout($reference);
            if (in_array($payout['status'], ['sent','success','succeeded'], true)) {
                $this->complete((string) $row['id'], (string) $row['tenant_id'], (int) $row['amount_minor_units']);
                return 'sent';
            }
            if (in_array($payout['status'], ['failed','declined','canceled','cancelled'], true)) {
                $this->connection->executeStatement("UPDATE operator_payout_requests SET state = 'failed', updated_at = ? WHERE id = ? AND state <> 'sent'", [(new \DateTimeImmutable())->format('Y-m-d H:i:s'), $row['id']]);
                return 'failed';
            }
            $this->connection->executeStatement("UPDATE operator_payout_requests SET state = 'pending', updated_at = ? WHERE id = ? AND state <> 'sent'", [(new \DateTimeImmutable())->format('Y-m-d H:i:s'), $row['id']]);
            return 'pending';
        } catch (\Throwable) {
            $this->connection->executeStatement("UPDATE operator_payout_requests SET state = 'ambiguous', updated_at = ? WHERE id = ? AND state <> 'sent'", [(new \DateTimeImmutable())->format('Y-m-d H:i:s'), $row['id']]);
            return 'ambiguous';
        }
    }

    private function complete(string $id, string $tenantId, int $amount): void
    {
        $this->connection->transactional(function (Connection $connection) use ($id, $tenantId, $amount): void {
            $request = $connection->fetchAssociative('SELECT state FROM operator_payout_requests WHERE id = ? FOR UPDATE', [$id]);
            if (!is_array($request) || $request['state'] === 'sent') return;
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $connection->executeStatement("UPDATE operator_payout_requests SET state = 'sent', sent_at = ?, updated_at = ? WHERE id = ?", [$now, $now, $id]);
            $connection->executeStatement('INSERT IGNORE INTO operator_wallet_entries (id, tenant_id, source_type, source_reference, source_reference_hash, entry_type, amount_delta_minor, currency, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                ['wallet_'.substr(hash('sha256', $tenantId."\0".$id."\0payout_debit"), 0, 40), $tenantId, 'payout', $id, hash('sha256', $id), 'payout_debit', -$amount, 'XOF', $now]);
        });
    }
}
