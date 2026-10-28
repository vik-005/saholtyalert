<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

final class Version20261028143000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Restaure les dossiers Urgence 72h manquants pour les alertes élevées validées et déjà notifiées';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO urgence_72h_case (
                alert_id, date_activation, statut_case, activation_manuelle
            )
            SELECT
                a.id,
                COALESCE(
                    (SELECT MIN(n.created_at)
                     FROM notification n
                     WHERE n.alert_id = a.id AND n.type = 'urgence'),
                    a.date_validation,
                    a.created_at
                ),
                'active',
                0
            FROM alert a
            LEFT JOIN urgence_72h_case c ON c.alert_id = a.id
            WHERE c.id IS NULL
              AND a.statut = 'validee'
              AND (
                    a.urgence IN ('72h', 'immediat')
                    OR COALESCE(a.score_surcharge, a.score_gei, 0) >= 14
                    OR COALESCE(a.niveau_priorite_surcharge, a.niveau_priorite) IN ('eleve', 'critique')
                    OR EXISTS (
                        SELECT 1
                        FROM notification n
                        WHERE n.alert_id = a.id AND n.type = 'urgence'
                    )
              )
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO urgence_72h_phase (
                urgence_case_id, phase, sla_heure_limite, date_debut, date_fin,
                en_retard, rappel_envoye
            )
            SELECT c.id, 'detection', c.date_activation, c.date_activation, c.date_activation, 0, 0
            FROM urgence_72h_case c
            WHERE NOT EXISTS (
                SELECT 1 FROM urgence_72h_phase p
                WHERE p.urgence_case_id = c.id AND p.phase = 'detection'
            )
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO urgence_72h_phase (
                urgence_case_id, phase, sla_heure_limite, date_debut, en_retard, rappel_envoye
            )
            SELECT c.id, 'coordination', DATE_ADD(c.date_activation, INTERVAL 48 HOUR), c.date_activation, 0, 0
            FROM urgence_72h_case c
            WHERE NOT EXISTS (
                SELECT 1 FROM urgence_72h_phase p
                WHERE p.urgence_case_id = c.id AND p.phase = 'coordination'
            )
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO urgence_72h_phase (
                urgence_case_id, phase, sla_heure_limite, en_retard, rappel_envoye
            )
            SELECT c.id, 'suivi', DATE_ADD(c.date_activation, INTERVAL 72 HOUR), 0, 0
            FROM urgence_72h_case c
            WHERE NOT EXISTS (
                SELECT 1 FROM urgence_72h_phase p
                WHERE p.urgence_case_id = c.id AND p.phase = 'suivi'
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration('Les dossiers Urgence 72h restaurés sont des données métier et ne peuvent pas être supprimés automatiquement.');
    }
}
