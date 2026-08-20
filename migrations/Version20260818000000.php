<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260818000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Normalise les impacts historiques vers Élevé, Moyen ou Faible.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE alert SET impact = 'eleve' WHERE impact = 'tres_eleve'");
        $this->addSql("UPDATE alert SET impact = 'moyen' WHERE impact = 'moyen_eleve'");
    }

    public function down(Schema $schema): void
    {
        // La normalisation ne peut pas restaurer de manière fiable les anciennes valeurs.
    }
}
