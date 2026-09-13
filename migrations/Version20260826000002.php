<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajout des champs deleted_at et commentaire_rejet dans la table alert.
 */
final class Version20260826000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Soft-delete (deleted_at) et commentaire de rejet Manager sur la table alert';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE alert ADD deleted_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE alert ADD commentaire_rejet LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE alert DROP COLUMN deleted_at');
        $this->addSql('ALTER TABLE alert DROP COLUMN commentaire_rejet');
    }
}
