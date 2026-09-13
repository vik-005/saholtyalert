<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration pour nettoyer les anciennes phases obsolètes 'qualification' et 'validation'
 * dans la table urgence_72h_phase suite à la restructuration de l'enum PhaseUrgence
 * vers le protocole strict à 3 phases : Détection (T0), Coordination (T+48h), Suivi & clôture (T+72h).
 */
final class Version20260912000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Nettoyage des phases obsolètes (qualification, validation) dans urgence_72h_phase et alignement sur le protocole Urgence 72h à 3 phases.';
    }

    public function up(Schema $schema): void
    {
        // 1. Supprimer les phases qui n'existent plus dans l'enum PhaseUrgence
        $this->addSql("DELETE FROM urgence_72h_phase WHERE phase NOT IN ('detection', 'coordination', 'suivi')");

        // 2. Aligner les phases de détection existantes : complétées dès T0 si non renseignées
        $this->addSql("
            UPDATE urgence_72h_phase p
            INNER JOIN urgence_72h_case c ON p.urgence_case_id = c.id
            SET p.date_debut = c.date_activation, p.date_fin = c.date_activation
            WHERE p.phase = 'detection' AND p.date_debut IS NULL
        ");

        // 3. Aligner la phase coordination : démarrée à T0 si non renseignée
        $this->addSql("
            UPDATE urgence_72h_phase p
            INNER JOIN urgence_72h_case c ON p.urgence_case_id = c.id
            SET p.date_debut = c.date_activation
            WHERE p.phase = 'coordination' AND p.date_debut IS NULL
        ");
    }

    public function down(Schema $schema): void
    {
        // Aucune restauration possible ni souhaitable pour les phases obsolètes
    }
}
