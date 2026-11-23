<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261120150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add encrypted tenant-owned RouterOS credential storage.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE tenant_router_credentials (tenant_id VARCHAR(80) NOT NULL, credential_reference VARCHAR(160) NOT NULL, encrypted_credentials LONGTEXT NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (tenant_id, credential_reference)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE tenant_router_credentials');
    }
}
