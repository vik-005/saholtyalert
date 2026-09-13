<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajout du champ operateur_acteur sur la table alert.
 * Position Excel officielle : À CONFIRMER (non définie dans la documentation source).
 */
final class Version20260912160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout du champ operateur_acteur (VARCHAR(255) nullable) sur la table alert.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE alert ADD operateur_acteur VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE alert DROP operateur_acteur');
    }
}
