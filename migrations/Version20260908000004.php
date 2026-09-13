<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Correctif de déploiement idempotent : certaines bases ont déjà enregistré
 * la version 20260908000002 avant l'ajout des colonnes d'audit.
 */
final class Version20260908000004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Garantit la présence des scores avant/après dans l’historique de qualification.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE alert_qualification_history ADD COLUMN IF NOT EXISTS old_score INT DEFAULT NULL, ADD COLUMN IF NOT EXISTS new_score INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE alert_qualification_history DROP COLUMN IF EXISTS old_score, DROP COLUMN IF EXISTS new_score');
    }
}
