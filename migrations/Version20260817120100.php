<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Initialisation des seuils de scoring GEI dans la table rule_config.
 * Partie B.3 — valeurs conformes à l'Annexe C.
 *
 * Note : addSql() dans Doctrine Migrations n'accepte PAS de paramètres nommés.
 * On utilise des valeurs littérales échappées directement dans le SQL.
 */
final class Version20260817120100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initialisation des seuils de scoring GEI dans rule_config (Annexe C)';
    }

    public function up(Schema $schema): void
    {
        // INSERT IGNORE : ne recrée pas si la clé existe déjà
        $seuils = [
            ['score_critique',            '18', 'Seuil minimum priorité Critique et Urgence 72h', 'scoring'],
            ['score_eleve_min',           '14', 'Seuil minimum priorité Elevee',                  'scoring'],
            ['score_modere_min',          '10', 'Seuil minimum priorité Moderee',                 'scoring'],
            ['sla_72h_heures',            '72', 'Délai maximal SLA procédure urgence (heures)',   'sla'],
            ['sla_analyse_rapide_heures', '6',  'Délai tâche analyse rapide regle 3 (heures)',    'sla'],
        ];

        foreach ($seuils as [$cle, $valeur, $description, $categorie]) {
            // Échappement manuel — pas de paramètres dans addSql Migrations
            $this->addSql(sprintf(
                "INSERT IGNORE INTO rule_config (cle, valeur, description, categorie, modifiable, actif)
                 VALUES ('%s', '%s', '%s', '%s', 1, 1)",
                addslashes($cle),
                addslashes($valeur),
                addslashes($description),
                addslashes($categorie)
            ));
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM rule_config WHERE cle IN (
            'score_critique',
            'score_eleve_min',
            'score_modere_min',
            'sla_72h_heures',
            'sla_analyse_rapide_heures'
        )");
    }
}
