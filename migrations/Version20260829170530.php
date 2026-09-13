<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260829170530 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX idx_alert_date ON alert');
        $this->addSql('DROP INDEX idx_alert_statut ON alert');
        $this->addSql('DROP INDEX idx_alert_date_statut ON alert');
        $this->addSql('DROP INDEX idx_alert_priorite ON alert');
        $this->addSql('DROP INDEX idx_alert_market ON alert');
        $this->addSql('DROP INDEX idx_alert_statut_market ON alert');
        $this->addSql('DROP INDEX idx_alert_emetteur ON alert');
        $this->addSql('ALTER TABLE alert ADD type_localisation VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE alert DROP type_localisation');
        $this->addSql('CREATE INDEX idx_alert_date ON alert (date_creation)');
        $this->addSql('CREATE INDEX idx_alert_statut ON alert (statut)');
        $this->addSql('CREATE INDEX idx_alert_date_statut ON alert (date_creation, statut)');
        $this->addSql('CREATE INDEX idx_alert_priorite ON alert (niveau_priorite)');
        $this->addSql('CREATE INDEX idx_alert_market ON alert (market_id)');
        $this->addSql('CREATE INDEX idx_alert_statut_market ON alert (statut, market_id)');
        $this->addSql('CREATE INDEX idx_alert_emetteur ON alert (emetteur_id)');
    }
}
