<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Retains the exact access point for safely revoking an expiring voucher. */
final class Version20261114171100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the router that received each provisioned voucher.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vouchers ADD router_id VARCHAR(80) DEFAULT NULL');
        $this->addSql("UPDATE vouchers v INNER JOIN (SELECT tenant_id, site_id, MIN(id) AS router_id FROM routers GROUP BY tenant_id, site_id HAVING COUNT(*) = 1) r ON r.tenant_id = v.tenant_id AND r.site_id = v.site_id SET v.router_id = r.router_id");
        $this->addSql('CREATE INDEX idx_voucher_expiry_router ON vouchers (router_id, status, expires_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_voucher_expiry_router ON vouchers');
        $this->addSql('ALTER TABLE vouchers DROP router_id');
    }
}
