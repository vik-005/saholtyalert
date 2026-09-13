<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajout des index de performance sur la table alert pour les filtres fréquents.
 */
final class Version20260827014500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout index performance sur alert (statut, market, emetteur, date, priorite)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_alert_statut ON alert (statut)');
        $this->addSql('CREATE INDEX idx_alert_market ON alert (market_id)');
        $this->addSql('CREATE INDEX idx_alert_emetteur ON alert (emetteur_id)');
        $this->addSql('CREATE INDEX idx_alert_date ON alert (date_creation)');
        $this->addSql('CREATE INDEX idx_alert_priorite ON alert (niveau_priorite)');
        $this->addSql('CREATE INDEX idx_alert_statut_market ON alert (statut, market_id)');
        $this->addSql('CREATE INDEX idx_alert_date_statut ON alert (date_creation, statut)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_alert_statut ON alert');
        $this->addSql('DROP INDEX idx_alert_market ON alert');
        $this->addSql('DROP INDEX idx_alert_emetteur ON alert');
        $this->addSql('DROP INDEX idx_alert_date ON alert');
        $this->addSql('DROP INDEX idx_alert_priorite ON alert');
        $this->addSql('DROP INDEX idx_alert_statut_market ON alert');
        $this->addSql('DROP INDEX idx_alert_date_statut ON alert');
    }
}
