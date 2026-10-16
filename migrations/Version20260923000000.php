<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260923000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add an optional legal classification to alerts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE alert ADD legal_status VARCHAR(10) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE alert DROP legal_status');
    }
}
