<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260922000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store alert route countries independently from the Market entity';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE alert ADD parcours_countries JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE alert DROP parcours_countries');
    }
}