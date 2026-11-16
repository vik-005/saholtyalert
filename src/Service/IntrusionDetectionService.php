<?php

namespace App\Service;

use App\Entity\SecurityAuditLog;
use App\Entity\SecurityAlert;
use App\Entity\SecurityRule;
use App\Repository\SecurityAuditLogRepository;
use App\Repository\SecurityRuleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service de détection des intrusions.
 * Analyse les événements de sécurité pour détecter les patterns d'attaque.
 *
 * Détecte :
 * - Brute force (multiples tentatives échouées)
 * - Scanning (exploration de routes inexistantes)
 * - Automatisation (requêtes à intervalles réguliers)
 * - Comportement suspect (géolocalisation inhabituelle, accès anormaux)
 */
class IntrusionDetectionService
{
    public function __construct(
        private readonly SecurityAuditLogRepository $auditLogRepository,
        private readonly SecurityRuleRepository $ruleRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ?SecurityAlertEmailService $emailService = null,
    ) {}

    /**
     * Analyse un événement d'audit pour détecter les intrusions
     */
    public function analyzeEvent(SecurityAuditLog $auditLog): ?SecurityAlert
    {
        $rules = $this->ruleRepository->findEnabled();
        
        foreach ($rules as $rule) {
            if (!$this->matchesFilterCriteria($auditLog, $rule)) {
                continue;
            }

            $alert = match ($rule->getRuleType()) {
                SecurityRule::TYPE_BRUTE_FORCE => $this->detectBruteForce($auditLog, $rule),
                SecurityRule::TYPE_SCANNING => $this->detectScanning($auditLog, $rule),
                SecurityRule::TYPE_AUTOMATED_REQUEST => $this->detectAutomation($auditLog, $rule),
                SecurityRule::TYPE_GEOGRAPHIC_ANOMALY => $this->detectGeographicAnomaly($auditLog, $rule),
                SecurityRule::TYPE_SENSITIVE_RESOURCE => $this->detectSensitiveResourceAccess($auditLog, $rule),
                default => null,
            };

            if ($alert !== null) {
                $this->entityManager->persist($alert);
                // Envoyer notification email si alerte critique ou high
                if ($this->emailService !== null) {
                    try {
                        $this->emailService->sendAlertNotification($alert);
                    } catch (\Throwable $e) {
                        // Ne jamais bloquer la détection pour un email raté
                    }
                }
                return $alert;
            }
        }

        return null;
    }

    /**
     * Détecte les attaques brute force
     */
    private function detectBruteForce(SecurityAuditLog $auditLog, SecurityRule $rule): ?SecurityAlert
    {
        if ($auditLog->getEventType() !== SecurityAuditLog::EVENT_LOGIN_FAILED) {
            return null;
        }

        $threshold = $rule->getThresholdCount() ?? 5;
        $timeWindow = $rule->getThresholdTimeSeconds() ?? 900; // 15 minutes

        $since = $auditLog->getCreatedAt()->modify('-' . $timeWindow . ' seconds');

        $failedAttempts = count($this->auditLogRepository->findBy([
            'eventType' => SecurityAuditLog::EVENT_LOGIN_FAILED,
            'ipAddress' => $auditLog->getIpAddress(),
        ]));

        if ($failedAttempts >= $threshold) {
            $alert = new SecurityAlert();
            $alert->setAlertType(SecurityAlert::ALERT_BRUTE_FORCE);
            $alert->setSeverity(SecurityAlert::SEVERITY_HIGH);
            $alert->setTitle('Brute Force Attack Detected');
            $alert->setDescription(sprintf(
                '%d failed login attempts from %s in %d seconds',
                $failedAttempts,
                $auditLog->getIpAddress(),
                $timeWindow
            ));
            $alert->setIpAddress($auditLog->getIpAddress());
            $alert->setUser($auditLog->getUser());
            $alert->setConfidenceScore(90);
            $alert->setEventCount($failedAttempts);
            $alert->setRecommendedActions([
                'Block IP address immediately',
                'Review login attempts for this IP',
                'Check for multiple targeted accounts',
                'Consider implementing CAPTCHA',
            ]);

            return $alert;
        }

        return null;
    }

    /**
     * Détecte les scans de routes
     */
    private function detectScanning(SecurityAuditLog $auditLog, SecurityRule $rule): ?SecurityAlert
    {
        if (!in_array($auditLog->getEventType(), [
            SecurityAuditLog::EVENT_ROUTE_NOT_FOUND,
            SecurityAuditLog::EVENT_FORBIDDEN_ACCESS,
        ])) {
            return null;
        }

        $threshold = $rule->getThresholdCount() ?? 20;
        $timeWindow = $rule->getThresholdTimeSeconds() ?? 300; // 5 minutes

        $since = $auditLog->getCreatedAt()->modify('-' . $timeWindow . ' seconds');

        // Count distinct routes accessed with 404 or 403
        $qb = $this->entityManager->createQueryBuilder();
        $count = (int) $qb
            ->select('COUNT(DISTINCT sal.urlPath)')
            ->from(SecurityAuditLog::class, 'sal')
            ->where('sal.ipAddress = :ip')
            ->andWhere('sal.createdAt >= :since')
            ->andWhere('sal.eventType IN (:eventTypes)')
            ->setParameter('ip', $auditLog->getIpAddress())
            ->setParameter('since', $since)
            ->setParameter('eventTypes', [
                SecurityAuditLog::EVENT_ROUTE_NOT_FOUND,
                SecurityAuditLog::EVENT_FORBIDDEN_ACCESS,
            ])
            ->getQuery()
            ->getSingleScalarResult();

        if ($count >= $threshold) {
            $alert = new SecurityAlert();
            $alert->setAlertType(SecurityAlert::ALERT_SCANNING);
            $alert->setSeverity(SecurityAlert::SEVERITY_MEDIUM);
            $alert->setTitle('Port/Route Scanning Detected');
            $alert->setDescription(sprintf(
                '%d distinct routes scanned from %s in %d seconds',
                $count,
                $auditLog->getIpAddress(),
                $timeWindow
            ));
            $alert->setIpAddress($auditLog->getIpAddress());
            $alert->setConfidenceScore(75);
            $alert->setEventCount($count);
            $alert->setRecommendedActions([
                'Review scanning patterns',
                'Consider blocking scanning IPs',
                'Implement rate limiting',
            ]);

            return $alert;
        }

        return null;
    }

    /**
     * Détecte les requêtes automatisées
     */
    private function detectAutomation(SecurityAuditLog $auditLog, SecurityRule $rule): ?SecurityAlert
    {
        // Critères d'automatisation
        $isAutomated = false;
        $automationScore = 0;

        // User-Agent vide ou suspect
        if (empty($auditLog->getUserAgent())) {
            $automationScore += 30;
            $isAutomated = true;
        } elseif ($this->isBotUserAgent($auditLog->getUserAgent())) {
            $automationScore += 25;
            $isAutomated = true;
        }

        // Trop de requêtes trop vite
        $timeWindow = 5; // 5 secondes
        $since = $auditLog->getCreatedAt()->modify('-' . $timeWindow . ' seconds');

        $qb = $this->entityManager->createQueryBuilder();
        $recentCount = (int) $qb
            ->select('COUNT(sal.id)')
            ->from(SecurityAuditLog::class, 'sal')
            ->where('sal.ipAddress = :ip')
            ->andWhere('sal.createdAt >= :since')
            ->setParameter('ip', $auditLog->getIpAddress())
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();

        if ($recentCount >= ($rule->getThresholdCount() ?? 50)) {
            $automationScore += 35;
            $isAutomated = true;
        }

        if ($isAutomated && $automationScore >= 60) {
            $alert = new SecurityAlert();
            $alert->setAlertType(SecurityAlert::ALERT_AUTOMATED);
            $alert->setSeverity(SecurityAlert::SEVERITY_MEDIUM);
            $alert->setTitle('Automated Activity Detected');
            $alert->setDescription(sprintf(
                'Automated requests detected from %s (score: %d)',
                $auditLog->getIpAddress(),
                $automationScore
            ));
            $alert->setIpAddress($auditLog->getIpAddress());
            $alert->setConfidenceScore($automationScore);
            $alert->setEventCount($recentCount);
            $alert->setRecommendedActions([
                'Review request patterns',
                'Implement rate limiting or CAPTCHA',
                'Consider blocking if legitimate service',
            ]);

            return $alert;
        }

        return null;
    }

    /**
     * Détecte les anomalies géographiques
     */
    private function detectGeographicAnomaly(SecurityAuditLog $auditLog, SecurityRule $rule): ?SecurityAlert
    {
        // Impossible Travel Check : connexion de deux pays trop éloignés
        if ($auditLog->getUser() === null) {
            return null;
        }

        $userId = $auditLog->getUser()->getId();

        // Récupérer la dernière connexion
        $qb = $this->entityManager->createQueryBuilder();
        $lastLogin = $qb
            ->select('sal')
            ->from(SecurityAuditLog::class, 'sal')
            ->where('sal.user = :userId')
            ->andWhere('sal.eventType = :eventType')
            ->andWhere('sal.id != :currentId')
            ->setParameter('userId', $userId)
            ->setParameter('eventType', SecurityAuditLog::EVENT_LOGIN_SUCCESS)
            ->setParameter('currentId', $auditLog->getId())
            ->orderBy('sal.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($lastLogin === null || $lastLogin->getCountry() === null || $auditLog->getCountry() === null) {
            return null;
        }

        if ($lastLogin->getCountry() !== $auditLog->getCountry()) {
            // Différent pays : anomalie modérée
            $alert = new SecurityAlert();
            $alert->setAlertType(SecurityAlert::ALERT_SUSPICIOUS_BEHAVIOR);
            $alert->setSeverity(SecurityAlert::SEVERITY_LOW);
            $alert->setTitle('Geographic Anomaly');
            $alert->setDescription(sprintf(
                'User logged in from different country: %s → %s',
                $lastLogin->getCountry() ?? 'Unknown',
                $auditLog->getCountry() ?? 'Unknown'
            ));
            $alert->setIpAddress($auditLog->getIpAddress());
            $alert->setUser($auditLog->getUser());
            $alert->setConfidenceScore(50);
            $alert->setRecommendedActions([
                'Verify user account if necessary',
                'Check for account compromise',
            ]);

            return $alert;
        }

        return null;
    }

    /**
     * Détecte les accès à des ressources sensibles
     */
    private function detectSensitiveResourceAccess(SecurityAuditLog $auditLog, SecurityRule $rule): ?SecurityAlert
    {
        $sensitiveRoutes = [
            '/admin',
            '/api/admin',
            '/users/manage',
            '/security',
        ];

        $isSensitive = false;
        foreach ($sensitiveRoutes as $route) {
            if (str_starts_with($auditLog->getUrlPath() ?? '', $route)) {
                $isSensitive = true;
                break;
            }
        }

        if (!$isSensitive || $auditLog->getHttpStatusCode() === 200) {
            return null;
        }

        if ($auditLog->getHttpStatusCode() === 403) {
            $alert = new SecurityAlert();
            $alert->setAlertType(SecurityAlert::ALERT_UNAUTHORIZED_ACCESS);
            $alert->setSeverity(SecurityAlert::SEVERITY_MEDIUM);
            $alert->setTitle('Unauthorized Access Attempt to Sensitive Resource');
            $alert->setDescription(sprintf(
                'Attempt to access %s from %s',
                $auditLog->getUrlPath(),
                $auditLog->getIpAddress()
            ));
            $alert->setIpAddress($auditLog->getIpAddress());
            $alert->setTargetResource($auditLog->getUrlPath());
            $alert->setUser($auditLog->getUser());
            $alert->setConfidenceScore(80);
            $alert->setRecommendedActions([
                'Review access logs for this user',
                'Check if account is compromised',
                'Implement additional monitoring',
            ]);

            return $alert;
        }

        return null;
    }

    /**
     * Vérifie les critères de filtre d'une règle
     */
    private function matchesFilterCriteria(SecurityAuditLog $auditLog, SecurityRule $rule): bool
    {
        $filterCriteria = $rule->getFilterCriteria();
        
        if ($filterCriteria === null) {
            return true;
        }

        if (isset($filterCriteria['event_types'])) {
            if (!in_array($auditLog->getEventType(), $filterCriteria['event_types'])) {
                return false;
            }
        }

        if (isset($filterCriteria['routes'])) {
            $matched = false;
            foreach ($filterCriteria['routes'] as $route) {
                if (str_starts_with($auditLog->getUrlPath() ?? '', $route)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                return false;
            }
        }

        return true;
    }

    /**
     * Détecte les User-Agent de bots
     */
    private function isBotUserAgent(string $userAgent): bool
    {
        $botPatterns = [
            'bot',
            'crawler',
            'spider',
            'scanner',
            'curl',
            'wget',
            'python',
        ];

        foreach ($botPatterns as $pattern) {
            if (stripos($userAgent, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }
}
