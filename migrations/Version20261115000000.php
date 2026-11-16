<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261115000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create security audit tables for GEI platform';
    }

    public function up(Schema $schema): void
    {
        // Security Audit Log
        $this->addSql('CREATE TABLE security_audit_log (
            id BIGINT AUTO_INCREMENT NOT NULL,
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
            FOREIGN KEY (user_id) REFERENCES `user`(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        // IP Address Reputation
        $this->addSql('CREATE TABLE ip_address_reputation (
            id BIGINT AUTO_INCREMENT NOT NULL,
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

        // IP Blocklist
        $this->addSql('CREATE TABLE ip_blocklist (
            id BIGINT AUTO_INCREMENT NOT NULL,
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
            FOREIGN KEY (blocked_by_admin_id) REFERENCES `user`(id) ON DELETE SET NULL,
            FOREIGN KEY (unblocking_admin_id) REFERENCES `user`(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        // Security Alert
        $this->addSql('CREATE TABLE security_alert (
            id BIGINT AUTO_INCREMENT NOT NULL,
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
            FOREIGN KEY (user_id) REFERENCES `user`(id) ON DELETE SET NULL,
            FOREIGN KEY (acknowledged_by_admin_id) REFERENCES `user`(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        // Security Rule
        $this->addSql('CREATE TABLE security_rule (
            id BIGINT AUTO_INCREMENT NOT NULL,
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
            created_by_admin_id INT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_security_rule_type (rule_type),
            INDEX idx_security_rule_enabled (is_enabled),
            FOREIGN KEY (created_by_admin_id) REFERENCES `user`(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        // Security Investigation
        $this->addSql('CREATE TABLE security_investigation (
            id BIGINT AUTO_INCREMENT NOT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT NOT NULL,
            status VARCHAR(50) NOT NULL DEFAULT "open",
            ip_address VARCHAR(45) DEFAULT NULL,
            user_id INT DEFAULT NULL,
            security_alert_id BIGINT DEFAULT NULL,
            incident_type VARCHAR(100) DEFAULT NULL,
            severity VARCHAR(20) NOT NULL DEFAULT "medium",
            investigated_by_admin_id INT DEFAULT NULL,
            conclusions TEXT DEFAULT NULL,
            actions_taken TEXT DEFAULT NULL,
            event_timeline JSON DEFAULT NULL,
            evidence JSON DEFAULT NULL,
            metrics JSON DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            opened_at DATETIME DEFAULT NULL,
            closed_at DATETIME DEFAULT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_security_investigation_status (status),
            INDEX idx_security_investigation_ip (ip_address),
            INDEX idx_security_investigation_created (created_at),
            FOREIGN KEY (user_id) REFERENCES `user`(id) ON DELETE SET NULL,
            FOREIGN KEY (security_alert_id) REFERENCES security_alert(id) ON DELETE SET NULL,
            FOREIGN KEY (investigated_by_admin_id) REFERENCES `user`(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        // Insert default security rules
        $this->addSql("INSERT INTO security_rule (rule_type, name, description, is_enabled, threshold_count, threshold_time_seconds, confidence_score, alert_severity, recommended_actions, filter_criteria, created_at, updated_at) VALUES
            ('brute_force', 'Brute Force Detection', 'Detect multiple failed login attempts from same IP', 1, 5, 900, 90, 'high', '[\"Block IP immediately\", \"Review login attempts\", \"Check targeted accounts\"]', '{\"event_types\": [\"login_failed\"]}', NOW(), NOW()),
            ('scanning', 'Route Scanning Detection', 'Detect automated scanning of routes', 1, 20, 300, 75, 'medium', '[\"Review scanning patterns\", \"Implement rate limiting\"]', '{\"event_types\": [\"route_not_found\", \"forbidden_access\"]}', NOW(), NOW()),
            ('automated_request', 'Automated Request Detection', 'Detect non-human requests', 1, 50, 5, 80, 'medium', '[\"Review request patterns\", \"Implement CAPTCHA\"]', '{\"event_types\": [\"automated_request\"]}', NOW(), NOW()),
            ('sensitive_resource', 'Sensitive Resource Access', 'Detect unauthorized access to sensitive routes', 1, 1, 60, 85, 'high', '[\"Review access logs\", \"Check for compromise\"]', '{\"routes\": [\"/admin\", \"/api/admin\", \"/users\"]}', NOW(), NOW())
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS security_investigation');
        $this->addSql('DROP TABLE IF EXISTS security_rule');
        $this->addSql('DROP TABLE IF EXISTS security_alert');
        $this->addSql('DROP TABLE IF EXISTS ip_blocklist');
        $this->addSql('DROP TABLE IF EXISTS ip_address_reputation');
        $this->addSql('DROP TABLE IF EXISTS security_audit_log');
    }
}
