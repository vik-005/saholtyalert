<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261116010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create missing security tables if database was initialized without the security module migration.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS security_audit_log (
            id BIGINT AUTO_INCREMENT NOT NULL,
            PRIMARY KEY (id),
            correlation_id VARCHAR(36) NOT NULL,
            event_type VARCHAR(100) NOT NULL,
            user_id INT DEFAULT NULL,
            ip_address VARCHAR(45) NOT NULL,
            route_name VARCHAR(255) DEFAULT NULL,
            url_path VARCHAR(2048) DEFAULT NULL,
            http_method VARCHAR(10) DEFAULT NULL,
            http_status_code INT DEFAULT NULL,
            user_agent TEXT DEFAULT NULL,
            browser_name VARCHAR(100) DEFAULT NULL,
            os_name VARCHAR(100) DEFAULT NULL,
            country VARCHAR(100) DEFAULT NULL,
            region VARCHAR(100) DEFAULT NULL,
            isp VARCHAR(255) DEFAULT NULL,
            asn VARCHAR(50) DEFAULT NULL,
            network_type VARCHAR(50) DEFAULT NULL,
            is_vpn TINYINT(1) NOT NULL DEFAULT 0,
            is_proxy TINYINT(1) NOT NULL DEFAULT 0,
            is_tor TINYINT(1) NOT NULL DEFAULT 0,
            rejection_reason TEXT DEFAULT NULL,
            risk_score INT NOT NULL DEFAULT 0,
            risk_level VARCHAR(20) NOT NULL DEFAULT "normal",
            risk_factors JSON DEFAULT NULL,
            details JSON DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_security_audit_event_type (event_type),
            INDEX idx_security_audit_ip (ip_address),
            INDEX idx_security_audit_user (user_id),
            INDEX idx_security_audit_created (created_at),
            INDEX idx_security_audit_risk (risk_score),
            INDEX idx_security_audit_created_risk (created_at, risk_score),
            INDEX idx_security_audit_ip_created (ip_address, created_at),
            UNIQUE INDEX security_audit_correlation_id (correlation_id),
            CONSTRAINT FK_security_audit_log_user FOREIGN KEY (user_id) REFERENCES `user`(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $this->addSql('CREATE TABLE IF NOT EXISTS ip_address_reputation (
            id BIGINT AUTO_INCREMENT NOT NULL,
            PRIMARY KEY (id),
            ip_address VARCHAR(45) NOT NULL,
            ip_version INT NOT NULL DEFAULT 4,
            country VARCHAR(100) DEFAULT NULL,
            country_code VARCHAR(2) DEFAULT NULL,
            region VARCHAR(100) DEFAULT NULL,
            city VARCHAR(100) DEFAULT NULL,
            latitude DECIMAL(10, 6) DEFAULT NULL,
            longitude DECIMAL(10, 6) DEFAULT NULL,
            isp VARCHAR(255) DEFAULT NULL,
            asn VARCHAR(50) DEFAULT NULL,
            organization VARCHAR(255) DEFAULT NULL,
            network_type VARCHAR(50) DEFAULT NULL,
            is_vpn TINYINT(1) NOT NULL DEFAULT 0,
            is_proxy TINYINT(1) NOT NULL DEFAULT 0,
            is_tor TINYINT(1) NOT NULL DEFAULT 0,
            abuse_ip_db_score INT DEFAULT NULL,
            abuse_ip_db_report_count INT DEFAULT NULL,
            virus_total_detections INT DEFAULT NULL,
            risk_score INT NOT NULL DEFAULT 0,
            risk_categories JSON DEFAULT NULL,
            raw_data JSON DEFAULT NULL,
            verified_sources JSON DEFAULT NULL,
            last_checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ip_reputation_address (ip_address),
            INDEX idx_ip_reputation_checked (last_checked_at),
            INDEX idx_ip_reputation_risk (risk_score),
            INDEX idx_ip_reputation_anonymized (is_vpn, is_proxy, is_tor),
            UNIQUE INDEX ip_reputation_address (ip_address)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $this->addSql('CREATE TABLE IF NOT EXISTS ip_blocklist (
            id BIGINT AUTO_INCREMENT NOT NULL,
            PRIMARY KEY (id),
            ip_address VARCHAR(45) NOT NULL,
            is_whitelist TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            reason VARCHAR(100) NOT NULL,
            description TEXT DEFAULT NULL,
            risk_score_trigger INT DEFAULT NULL,
            blocked_by_admin_id INT DEFAULT NULL,
            blocked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME DEFAULT NULL,
            unblocking_at DATETIME DEFAULT NULL,
            unblocking_admin_id INT DEFAULT NULL,
            unblocking_reason TEXT DEFAULT NULL,
            country VARCHAR(100) DEFAULT NULL,
            asn VARCHAR(50) DEFAULT NULL,
            isp VARCHAR(255) DEFAULT NULL,
            attempts_since_blocage INT NOT NULL DEFAULT 0,
            metadata JSON DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ip_blocklist_address (ip_address),
            INDEX idx_ip_blocklist_blocked (blocked_at),
            INDEX idx_ip_blocklist_expires (expires_at),
            INDEX idx_ip_blocklist_whitelist (is_whitelist),
            INDEX idx_ip_blocklist_active (is_active),
            UNIQUE INDEX ip_blocklist_address (ip_address),
            CONSTRAINT FK_ip_blocklist_blocked_by FOREIGN KEY (blocked_by_admin_id) REFERENCES `user`(id) ON DELETE SET NULL,
            CONSTRAINT FK_ip_blocklist_unblocked_by FOREIGN KEY (unblocking_admin_id) REFERENCES `user`(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $this->addSql('CREATE TABLE IF NOT EXISTS security_alert (
            id BIGINT AUTO_INCREMENT NOT NULL,
            PRIMARY KEY (id),
            alert_type VARCHAR(100) NOT NULL,
            severity VARCHAR(20) NOT NULL DEFAULT "medium",
            status VARCHAR(20) NOT NULL DEFAULT "new",
            title VARCHAR(255) NOT NULL,
            description TEXT NOT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_id INT DEFAULT NULL,
            target_resource VARCHAR(255) DEFAULT NULL,
            confidence_score INT NOT NULL DEFAULT 0,
            event_count INT NOT NULL DEFAULT 1,
            details JSON DEFAULT NULL,
            recommended_actions JSON DEFAULT NULL,
            acknowledged_by_admin_id INT DEFAULT NULL,
            acknowledged_at DATETIME DEFAULT NULL,
            admin_notes TEXT DEFAULT NULL,
            detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            resolved_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_security_alert_type (alert_type),
            INDEX idx_security_alert_severity (severity),
            INDEX idx_security_alert_status (status),
            INDEX idx_security_alert_created (created_at),
            INDEX idx_security_alert_detected (detected_at),
            CONSTRAINT FK_security_alert_user FOREIGN KEY (user_id) REFERENCES `user`(id) ON DELETE SET NULL,
            CONSTRAINT FK_security_alert_acknowledged_by FOREIGN KEY (acknowledged_by_admin_id) REFERENCES `user`(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $this->addSql('CREATE TABLE IF NOT EXISTS security_rule (
            id BIGINT AUTO_INCREMENT NOT NULL,
            PRIMARY KEY (id),
            rule_type VARCHAR(100) NOT NULL,
            name VARCHAR(255) NOT NULL,
            description TEXT DEFAULT NULL,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            threshold_count INT DEFAULT NULL,
            threshold_time_seconds INT DEFAULT NULL,
            threshold_risk_score INT DEFAULT NULL,
            confidence_score INT NOT NULL DEFAULT 80,
            alert_severity VARCHAR(20) NOT NULL DEFAULT "medium",
            recommended_actions JSON DEFAULT NULL,
            filter_criteria JSON DEFAULT NULL,
            conditions JSON DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_security_rule_type (rule_type),
            INDEX idx_security_rule_enabled (is_enabled),
            INDEX idx_security_rule_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public function down(Schema $schema): void
    {
        // Intentionally left empty: the DB may hold produced data and this repair migration is idempotent.
    }
}
