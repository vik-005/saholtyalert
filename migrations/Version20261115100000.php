<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration du module géospatial GEI.
 *
 * Modifications :
 *   1. Ajout du champ `continent` sur la table `market`
 *   2. Ajout des index géographiques sur la table `alert`
 *   3. Ajout des index géographiques sur la table `zone_geo`
 *   4. Peuplement du continent selon le code ISO3 existant
 *
 * AUCUNE table existante n'est supprimée ni modifiée de façon destructrice.
 */
final class Version20261115100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Module géospatial GEI : continent sur Market, index géographiques sur Alert et ZoneGeo';
    }

    public function up(Schema $schema): void
    {
        // ── 1. Champ continent sur market ──────────────────────────────────
        $this->addSql("ALTER TABLE market
            ADD COLUMN continent VARCHAR(50) NOT NULL DEFAULT 'Afrique'
            AFTER region
        ");

        // ── 2. Index géographiques sur alert ──────────────────────────────
        // Index sur les coordonnées précises de localité (heatmap)
        $this->addSql('ALTER TABLE alert
            ADD INDEX idx_alert_geo_coords (localite_latitude, localite_longitude),
            ADD INDEX idx_alert_type_localisation (type_localisation),
            ADD INDEX idx_alert_date_market (date_creation, market_id),
            ADD INDEX idx_alert_score_market (score_gei, market_id)
        ');

        // ── 3. Index géographiques sur zone_geo ───────────────────────────
        $this->addSql('ALTER TABLE zone_geo
            ADD INDEX idx_zone_geo_coords (latitude, longitude),
            ADD INDEX idx_zone_geo_market (market_id)
        ');

        // ── 4. Peuplement du continent selon les ISO3 connus ──────────────
        // Tous les marchés GEI sont en Afrique subsaharienne — cohérent avec
        // le contexte (région = "Afrique de l'Ouest" par défaut dans l'entité).
        // La valeur 'Afrique' est déjà le DEFAULT. On distingue les sous-régions
        // pour permettre un filtrage choroplèthe continental.
        $this->addSql("UPDATE market SET continent = 'Afrique de l''Ouest' WHERE code_iso3 IN (
            'BEN','TGO','GHA','BFA','MLI','NER','SEN','CIV','GIN','NGA','SLE','GMB','GNB','MRT','CPV','LBR'
        )");
        $this->addSql("UPDATE market SET continent = 'Afrique Centrale' WHERE code_iso3 IN (
            'CMR','COD','COG','GAB','CAF','TCD','GNQ','STP','RWA','BDI','AGO'
        )");
        $this->addSql("UPDATE market SET continent = 'Afrique de l''Est' WHERE code_iso3 IN (
            'ETH','KEN','UGA','TZA','MOZ','ZMB','MWI','ZWE','MDG','SOM','ERI','DJI','SSD','SDN'
        )");
        $this->addSql("UPDATE market SET continent = 'Afrique du Nord' WHERE code_iso3 IN (
            'MAR','DZA','TUN','LBA','EGY','MRT'
        )");
        $this->addSql("UPDATE market SET continent = 'Afrique Australe' WHERE code_iso3 IN (
            'ZAF','NAM','BWA','LSO','SWZ'
        )");
        // Laisser le DEFAULT 'Afrique' pour les autres marchés non-africains si ajoutés ultérieurement
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE alert
            DROP INDEX IF EXISTS idx_alert_geo_coords,
            DROP INDEX IF EXISTS idx_alert_type_localisation,
            DROP INDEX IF EXISTS idx_alert_date_market,
            DROP INDEX IF EXISTS idx_alert_score_market
        ');

        $this->addSql('ALTER TABLE zone_geo
            DROP INDEX IF EXISTS idx_zone_geo_coords,
            DROP INDEX IF EXISTS idx_zone_geo_market
        ');

        $this->addSql('ALTER TABLE market DROP COLUMN continent');
    }
}
