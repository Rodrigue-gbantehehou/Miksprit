<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261120160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist voucher RouterOS usernames and allow repeated external session IDs over time.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vouchers ADD router_username VARCHAR(128) DEFAULT NULL');
        $this->addSql('DROP INDEX uniq_router_external_session ON hotspot_sessions');
        $this->addSql('CREATE INDEX idx_session_router_external_state ON hotspot_sessions (router_id, external_session_id, state)');
        $this->addSql('CREATE INDEX idx_voucher_router_username ON vouchers (tenant_id, router_id, router_username, status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_voucher_router_username ON vouchers');
        $this->addSql('DROP INDEX idx_session_router_external_state ON hotspot_sessions');
        $this->addSql('CREATE UNIQUE INDEX uniq_router_external_session ON hotspot_sessions (router_id, external_session_id)');
        $this->addSql('ALTER TABLE vouchers DROP router_username');
    }
}
