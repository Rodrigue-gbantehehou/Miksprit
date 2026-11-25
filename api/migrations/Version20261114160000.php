<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Persist the stable driver key used to select a Wi-Fi router kit. */
final class Version20261114160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add provider_code to routers with existing devices defaulted to MikroTik.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE routers ADD provider_code VARCHAR(32) NOT NULL DEFAULT 'mikrotik'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE routers DROP provider_code');
    }
}
