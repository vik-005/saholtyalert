<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260826223906 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE connection_position DROP FOREIGN KEY `FK_5C42D6A7A76ED395`');
        $this->addSql('ALTER TABLE connection_position CHANGE latitude latitude NUMERIC(10, 6) DEFAULT \'0.000000\' NOT NULL, CHANGE longitude longitude NUMERIC(10, 6) DEFAULT \'0.000000\' NOT NULL');
        $this->addSql('DROP INDEX idx_5c42d6a7a76ed395 ON connection_position');
        $this->addSql('CREATE INDEX IDX_E4309F61A76ED395 ON connection_position (user_id)');
        $this->addSql('ALTER TABLE connection_position ADD CONSTRAINT `FK_5C42D6A7A76ED395` FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE zone_geo DROP INDEX IDX_A6C7D3C88D9F6D38, ADD UNIQUE INDEX UNIQ_5D11D950622F3F37 (market_id)');
        $this->addSql('ALTER TABLE zone_geo CHANGE latitude latitude NUMERIC(10, 6) DEFAULT \'0.000000\' NOT NULL, CHANGE longitude longitude NUMERIC(10, 6) DEFAULT \'0.000000\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE connection_position DROP FOREIGN KEY FK_E4309F61A76ED395');
        $this->addSql('ALTER TABLE connection_position CHANGE latitude latitude NUMERIC(10, 6) NOT NULL, CHANGE longitude longitude NUMERIC(10, 6) NOT NULL');
        $this->addSql('DROP INDEX idx_e4309f61a76ed395 ON connection_position');
        $this->addSql('CREATE INDEX IDX_5C42D6A7A76ED395 ON connection_position (user_id)');
        $this->addSql('ALTER TABLE connection_position ADD CONSTRAINT FK_E4309F61A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE zone_geo DROP INDEX UNIQ_5D11D950622F3F37, ADD INDEX IDX_A6C7D3C88D9F6D38 (market_id)');
        $this->addSql('ALTER TABLE zone_geo CHANGE latitude latitude NUMERIC(10, 6) NOT NULL, CHANGE longitude longitude NUMERIC(10, 6) NOT NULL');
    }
}
