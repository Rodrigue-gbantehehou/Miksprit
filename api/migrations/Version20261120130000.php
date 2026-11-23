<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261120130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track invitation accounts that need to set their first password.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD password_setup_required TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP password_setup_required');
    }
}
