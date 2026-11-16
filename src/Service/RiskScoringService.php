<?php

namespace App\Service;

use App\Entity\SecurityAuditLog;
use App\Entity\IpBlocklist;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Service de calcul du score de risque.
 * Calcule un score 0-100 basé sur plusieurs facteurs de sécurité.
 *
 * 0-30   : Normal
 * 31-60  : Suspect
 * 61-80  : Probable automatisé/attaque
 * 81-100 : Menace élevée
 */
class RiskScoringService
{
    public function __construct(
        private readonly RequestStack $requestStack,
    ) {}

    /**
     * Calcule le score de risque pour un événement donné
     */
    public function calculateRiskScore(
        string $eventType,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $country,
        bool $isVpn,
        bool $isProxy,
        bool $isTor,
        int $failedLoginAttempts = 0,
        int $requestsPerSecond = 0,
        bool $unknownUser = false,
    ): array {
        $score = 0;
        $factors = [];

        // ── Événement ──────────────────────────────────────────────────

        $eventRisk = match ($eventType) {
            SecurityAuditLog::EVENT_LOGIN_SUCCESS => 0,
            SecurityAuditLog::EVENT_LOGIN_FAILED => 15,
            SecurityAuditLog::EVENT_LOGOUT => 0,
            SecurityAuditLog::EVENT_UNKNOWN_ACCOUNT => 30,
            SecurityAuditLog::EVENT_FORBIDDEN_ACCESS => 35,
            SecurityAuditLog::EVENT_ROUTE_NOT_FOUND => 20,
            SecurityAuditLog::EVENT_HTTP_ERROR => 10,
            SecurityAuditLog::EVENT_AUTOMATED_REQUEST => 40,
            SecurityAuditLog::EVENT_SENSITIVE_RESOURCE => 25,
            default => 5,
        };

        if ($eventRisk > 0) {
            $factors['event_type'] = $eventRisk;
            $score += $eventRisk;
        }

        // ── Authentification ────────────────────────────────────────────

        if ($unknownUser) {
            $factors['unknown_user'] = 25;
            $score += 25;
        }

        if ($failedLoginAttempts > 0) {
            $failedLoginScore = min(40, $failedLoginAttempts * 8);
            $factors['failed_login_attempts'] = $failedLoginScore;
            $score += $failedLoginScore;
        }

        // ── User-Agent ──────────────────────────────────────────────────

        if (empty($userAgent)) {
            $factors['missing_user_agent'] = 15;
            $score += 15;
        } elseif ($this->isSuspiciousUserAgent($userAgent)) {
            $factors['suspicious_user_agent'] = 20;
            $score += 20;
        }

        // ── Géolocalisation ──────────────────────────────────────────────

        if ($country === null) {
            $factors['unknown_country'] = 5;
            $score += 5;
        }

        // ── Anonymisation IP ────────────────────────────────────────────

        if ($isTor) {
            $factors['tor_detected'] = 35;
            $score += 35;
        } elseif ($isVpn) {
            $factors['vpn_detected'] = 20;
            $score += 20;
        } elseif ($isProxy) {
            $factors['proxy_detected'] = 15;
            $score += 15;
        }

        // ── Taux de requêtes ────────────────────────────────────────────

        if ($requestsPerSecond >= 100) {
            $factors['high_request_rate'] = 40;
            $score += 40;
        } elseif ($requestsPerSecond >= 10) {
            $factors['elevated_request_rate'] = 20;
            $score += 20;
        }

        // ── Normalisation à 100 ────────────────────────────────────────

        $score = min(100, $score);

        // ── Déterminer le niveau de risque ──────────────────────────────

        $level = match (true) {
            $score <= 30 => SecurityAuditLog::RISK_NORMAL,
            $score <= 60 => SecurityAuditLog::RISK_SUSPICIOUS,
            $score <= 80 => SecurityAuditLog::RISK_PROBABLE,
            default => SecurityAuditLog::RISK_THREAT,
        };

        return [
            'score' => $score,
            'level' => $level,
            'factors' => $factors,
        ];
    }

    /**
     * Détecte les User-Agent suspects
     */
    private function isSuspiciousUserAgent(string $userAgent): bool
    {
        $suspiciousPatterns = [
            'curl',
            'wget',
            'python',
            'java(?!.*script)',
            'node',
            'scrapy',
            'bot(?!google)',
            'spider',
            'scanner',
            'nikto',
            'nmap',
            'masscan',
            'shodan',
            'sqlmap',
            'metasploit',
            'zap',
            'burp',
        ];

        foreach ($suspiciousPatterns as $pattern) {
            if (preg_match("/$pattern/i", $userAgent)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Calcule un score agrégé pour une IP basée sur son historique
     */
    public function calculateIpReputationScore(
        int $abuseIpDbScore = 0,
        int $failedLoginCount = 0,
        int $scannedRoutesCount = 0,
        int $nonAuthorizedAccessAttempts = 0,
        bool $isVpn = false,
        bool $isProxy = false,
        bool $isTor = false,
    ): int {
        $score = 0;

        // AbuseIPDB score (0-100) → convertir en 0-30
        $score += min(30, ($abuseIpDbScore / 100) * 30);

        // Tentatives échouées
        $failedLoginScore = min(20, $failedLoginCount * 2);
        $score += $failedLoginScore;

        // Routes scannées
        $scanningScore = min(15, $scannedRoutesCount * 3);
        $score += $scanningScore;

        // Tentatives d'accès non autorisé
        $unauthorizedScore = min(15, $nonAuthorizedAccessAttempts * 2);
        $score += $unauthorizedScore;

        // Anonymisation
        if ($isTor) {
            $score += 15;
        } elseif ($isVpn) {
            $score += 8;
        } elseif ($isProxy) {
            $score += 5;
        }

        return min(100, (int) $score);
    }
}
