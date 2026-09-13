<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration GEI — Prompt Expert Final (Parties B, C, D)
 *
 * Changements :
 *   1. CREATE TABLE agent_marches (user_id, market_id) — relation N-N EMETTEUR_TERRAIN↔Market (Partie C)
 *   2. ALTER alert ADD validated_by_id — FK User nullable, Manager qui a validé (Partie B)
 *   3. ALTER alert ADD date_validation — DATETIME nullable, horodatage de validation (Partie D)
 *   4. INDEX sur alert.validated_by_id pour les filtres par Manager
 */
final class Version20260908000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Partie B/C/D : table agent_marches (N-N), colonne validated_by_id et date_validation sur alert';
    }

    public function up(Schema $schema): void
    {
        // 1 — Table de jonction Agent↔Marché (plusieurs-à-plusieurs)
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS agent_marches (
                user_id   INT NOT NULL,
                market_id INT NOT NULL,
                PRIMARY KEY (user_id, market_id),
                INDEX idx_am_user   (user_id),
                INDEX idx_am_market (market_id),
                CONSTRAINT fk_am_user   FOREIGN KEY (user_id)   REFERENCES `user`  (id) ON DELETE CASCADE,
                CONSTRAINT fk_am_market FOREIGN KEY (market_id) REFERENCES market (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
SQL);

        // 2 — Manager validateur sur l'alerte (traçabilité nominative Partie B)
        $this->addSql(<<<'SQL'
            ALTER TABLE alert
                ADD COLUMN IF NOT EXISTS validated_by_id INT DEFAULT NULL,
                ADD CONSTRAINT fk_alert_validated_by
                    FOREIGN KEY (validated_by_id) REFERENCES `user` (id) ON DELETE SET NULL
SQL);

        // 3 — Horodatage de validation (Partie D)
        $this->addSql(<<<'SQL'
            ALTER TABLE alert
                ADD COLUMN IF NOT EXISTS date_validation DATETIME DEFAULT NULL COMMENT 'Date/heure de validation par le Manager (Partie D)'
SQL);

        // 4 — Index pour filtres Manager (Partie B)
        $this->addSql(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_alert_validated_by ON alert (validated_by_id)
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_alert_validated_by ON alert');
        $this->addSql('ALTER TABLE alert DROP FOREIGN KEY IF EXISTS fk_alert_validated_by');
        $this->addSql('ALTER TABLE alert DROP COLUMN IF EXISTS date_validation');
        $this->addSql('ALTER TABLE alert DROP COLUMN IF EXISTS validated_by_id');
        $this->addSql('DROP TABLE IF EXISTS agent_marches');
    }
}
