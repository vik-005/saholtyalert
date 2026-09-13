<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Seed des coordonnées géographiques pour la cartographie Leaflet.
 *
 * Marchés couverts par le système GEI (Afrique de l'Ouest + centre) :
 *   BEN = Bénin            — Cotonou
 *   TGO = Togo             — Lomé
 *   NER = Niger            — Niamey
 *   MLI = Mali             — Bamako
 *   SEN = Sénégal          — Dakar
 *   CIV = Côte d'Ivoire    — Abidjan
 *   GHA = Ghana            — Accra
 *   GIN = Guinée           — Conakry
 *   BFA = Burkina Faso     — Ouagadougou
 *   CMR = Cameroun         — Yaoundé
 *
 * Ces coordonnées pointent sur la capitale de chaque pays (centre de gravité raisonnable).
 * Elles peuvent être affinées ultérieurement pour pointer sur les corridors douaniers
 * principaux en mettant à jour la table zone_geo directement.
 *
 * Prérequis : la table market doit exister avec les codeIso3 correspondants.
 */
final class Version20260826000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed coordonnées géographiques zone_geo pour cartographie Leaflet (10 marchés Afrique de l\'Ouest)';
    }

    public function up(Schema $schema): void
    {
        // Coordonnées : latitude, longitude, nom
        $zones = [
            ['BEN', 6.3702,  2.3912,  'Bénin'],
            ['TGO', 6.1375,  1.2123,  'Togo'],
            ['NER', 13.5137, 2.1098,  'Niger'],
            ['MLI', 12.6392, -8.0029, 'Mali'],
            ['SEN', 14.6928, -17.4467,'Sénégal'],
            ['CIV', 5.3600,  -4.0083, 'Côte d\'Ivoire'],
            ['GHA', 5.5560,  -0.1969, 'Ghana'],
            ['GIN', 9.5370,  -13.6773,'Guinée'],
            ['BFA', 12.3714, -1.5197, 'Burkina Faso'],
            ['CMR', 3.8480,  11.5021, 'Cameroun'],
        ];

        foreach ($zones as [$iso3, $lat, $lon, $nom]) {
            // Récupère l'ID du marché correspondant (si existant)
            $this->addSql(sprintf(
                "INSERT INTO zone_geo (market_id, latitude, longitude, nom)
                 SELECT id, %.6f, %.6f, '%s'
                 FROM market
                 WHERE code_iso3 = '%s'
                   AND NOT EXISTS (
                     SELECT 1 FROM zone_geo z WHERE z.market_id = market.id
                   )
                 LIMIT 1",
                $lat,
                $lon,
                addslashes($nom),
                $iso3
            ));
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM zone_geo WHERE nom IN (
            \'Bénin\', \'Togo\', \'Niger\', \'Mali\', \'Sénégal\',
            \'Côte d\'\'Ivoire\', \'Ghana\', \'Guinée\', \'Burkina Faso\', \'Cameroun\'
        )');
    }
}
