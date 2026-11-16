<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recrée les index de performance sur la table alert.
 *
 * Contexte : Version20260829170530 a supprimé tous les index alert (statut,
 * date_creation, market_id, priorite…) pour ajouter type_localisation.
 * Cette migration les restaure et en ajoute des composites couvrant les
 * requêtes d'agrégation géospatiale du GeoAggregationService.
 *
 * Chaque ADD INDEX est conditionnel (IF NOT EXISTS) pour éviter les erreurs
 * si certains index ont été recréés manuellement entre-temps.
 */
final class Version20261116000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recrée les index de performance manquants sur alert (statut, market_id, date_creation, deleted_at)';
    }

    public function up(Schema $schema): void
    {
        // ── Index simples restaurés ──────────────────────────────────────
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_alert_statut       ON alert (statut)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_alert_market        ON alert (market_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_alert_date          ON alert (date_creation)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_alert_priorite      ON alert (niveau_priorite)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_alert_deleted_at    ON alert (deleted_at)');

        // ── Index composites pour GeoAggregationService ──────────────────
        // Couvre le prédicat de base de toutes les requêtes :
        //   WHERE deleted_at IS NULL AND market_id = ?
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_alert_del_market
            ON alert (deleted_at, market_id)');

        // Couvre le filtre de date sur la timeline
        //   WHERE deleted_at IS NULL AND date_creation >= ?
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_alert_del_date
            ON alert (deleted_at, date_creation)');

        // Couvre les agrégations par statut + market
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_alert_del_statut_market
            ON alert (deleted_at, statut, market_id)');

        // Couvre le filtrage par corridor (port_corridor IS NOT NULL + filtre)
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_alert_corridor
            ON alert (port_corridor, deleted_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_alert_statut          ON alert');
        $this->addSql('DROP INDEX IF EXISTS idx_alert_market           ON alert');
        $this->addSql('DROP INDEX IF EXISTS idx_alert_date             ON alert');
        $this->addSql('DROP INDEX IF EXISTS idx_alert_priorite         ON alert');
        $this->addSql('DROP INDEX IF EXISTS idx_alert_deleted_at       ON alert');
        $this->addSql('DROP INDEX IF EXISTS idx_alert_del_market       ON alert');
        $this->addSql('DROP INDEX IF EXISTS idx_alert_del_date         ON alert');
        $this->addSql('DROP INDEX IF EXISTS idx_alert_del_statut_market ON alert');
        $this->addSql('DROP INDEX IF EXISTS idx_alert_corridor         ON alert');
    }
}
