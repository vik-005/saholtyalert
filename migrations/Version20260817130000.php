<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration post-audit conformité (17/08/2026).
 *
 * Corrections apportées :
 *  1. Ajout colonne emetteur_texte — préservation du texte émetteur à l'import Excel
 *  2. Ajout colonne pieces_type — type de pièce(s) (ex. "manifeste", "photos")
 *  3. Extension des ENUM en base pour correspondre au registre réel Annexe B :
 *     - alert.categorie : ajout tabac_brut, importation_tabac, transit_cigarettes...
 *     - alert.type_source : ajout source_portuaire, source_frontiere, manifeste_maritime...
 *     - alert.impact : ajout tres_eleve, moyen_eleve
 *     - alert.exploitabilite : ajout exploitable, a_analyser, a_surveiller
 *     - alert.statut : ajout ouvert, en_cours_analyse, ouvert_prioritaire
 */
final class Version20260817130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Post-audit conformité : emetteur_texte, pieces_type, extension des ENUM Doctrine';
    }

    public function up(Schema $schema): void
    {
        // ── Nouvelles colonnes ───────────────────────────────────────────────────
        $this->addSql("ALTER TABLE alert
            ADD emetteur_texte VARCHAR(200) DEFAULT NULL COMMENT 'Texte libre émetteur importé depuis registre Excel',
            ADD pieces_type VARCHAR(100) DEFAULT NULL COMMENT 'Type de pièces dispo (ex: manifeste, photos)'
        ");

        // ── Extension ENUM alert.categorie ───────────────────────────────────────
        $this->addSql("ALTER TABLE alert MODIFY COLUMN categorie VARCHAR(50) DEFAULT NULL COMMENT 'AlertCategorie Enum'");

        // ── Extension ENUM alert.type_source ─────────────────────────────────────
        $this->addSql("ALTER TABLE alert MODIFY COLUMN type_source VARCHAR(50) DEFAULT NULL COMMENT 'TypeSource Enum'");

        // ── Extension ENUM alert.impact ──────────────────────────────────────────
        $this->addSql("ALTER TABLE alert MODIFY COLUMN impact VARCHAR(20) DEFAULT NULL COMMENT 'AlertImpact Enum'");

        // ── Extension ENUM alert.exploitabilite ──────────────────────────────────
        $this->addSql("ALTER TABLE alert MODIFY COLUMN exploitabilite VARCHAR(20) DEFAULT NULL COMMENT 'AlertExploitabilite Enum'");

        // ── Extension ENUM alert.statut ───────────────────────────────────────────
        $this->addSql("ALTER TABLE alert MODIFY COLUMN statut VARCHAR(30) NOT NULL DEFAULT 'nouveau' COMMENT 'AlertStatut Enum'");

        // ── Markets manquants (pays observés dans le registre réel) ───────────────
        $markets = [
            ['SEN', 'Sénégal'],
            ['GIN', 'Guinée'],
            ['AGO', 'Angola'],
            ['CMR', 'Cameroun'],
            ['COD', 'RD Congo'],
            ['NGA', 'Nigeria'],
            ['BFA', 'Burkina Faso'],
        ];

        foreach ($markets as [$iso3, $nom]) {
            $this->addSql(sprintf(
                "INSERT IGNORE INTO market (code_iso3, nom, actif) VALUES ('%s', '%s', 1)",
                $iso3,
                addslashes($nom)
            ));
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE alert DROP COLUMN emetteur_texte, DROP COLUMN pieces_type');
        $this->addSql("DELETE FROM market WHERE code_iso3 IN ('SEN','GIN','AGO','CMR','COD','NGA','BFA')");
    }
}
