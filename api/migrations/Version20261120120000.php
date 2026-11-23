<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261120120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add tenant-owned encrypted payment provider settings.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE tenant_payment_settings (tenant_id VARCHAR(80) NOT NULL, provider VARCHAR(40) NOT NULL, environment VARCHAR(12) NOT NULL, encrypted_credentials LONGTEXT NOT NULL, last_api_check_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (tenant_id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE tenant_payment_settings');
    }
}
