<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260917100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create alert_market join table for multi-market alerts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE alert_market (
            id INT AUTO_INCREMENT NOT NULL,
            alert_id INT NOT NULL,
            market_id INT NOT NULL,
            role VARCHAR(20) NOT NULL,
            ordre INT DEFAULT NULL,
            UNIQUE INDEX uniq_alert_market (alert_id, market_id),
            INDEX idx_alert_market_role (alert_id, role),
            INDEX IDX_8D1B4A8E9B7A4C7A (market_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE alert_market ADD CONSTRAINT FK_ALERT_MARKET_ALERT FOREIGN KEY (alert_id) REFERENCES alert (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE alert_market ADD CONSTRAINT FK_ALERT_MARKET_MARKET FOREIGN KEY (market_id) REFERENCES market (id) ON DELETE CASCADE');

        $this->addSql('INSERT INTO alert_market (alert_id, market_id, role, ordre)
            SELECT id, market_id, "principal", 1
            FROM alert
            WHERE market_id IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE alert_market DROP FOREIGN KEY FK_ALERT_MARKET_ALERT');
        $this->addSql('ALTER TABLE alert_market DROP FOREIGN KEY FK_ALERT_MARKET_MARKET');
        $this->addSql('DROP TABLE alert_market');
    }
}
