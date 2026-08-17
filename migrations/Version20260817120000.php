<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Partie A — Conformité Annexe A (Fiche Standard GEI, Section 3).
 * Ajout du champ historique_source sur la table alert.
 *
 * Justification : le champ "Historique source" est explicitement listé en section 3
 * de l'Annexe A comme rempli par l'AGENT. Il était absent de l'entité Alert et du
 * formulaire AlertType — non-conformité corrigée.
 */
final class Version20260817120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout colonne historique_source sur la table alert (Annexe A §3 — Agent)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE alert ADD historique_source LONGTEXT DEFAULT NULL COMMENT \'Section 3 Annexe A — rempli par l\\\'Agent\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE alert DROP historique_source');
    }
}
