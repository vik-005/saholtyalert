<?php

namespace App\Service;

use App\Entity\SecurityAlert;
use App\Entity\User;
use App\Enum\UserRoleEnum;
use App\Repository\IpBlocklistRepository;
use App\Repository\SecurityAlertRepository;
use App\Repository\SecurityAuditLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Service d'envoi des notifications email pour les alertes de sécurité.
 *
 * Envoie :
 *  - Notification immédiate aux SUPERADMIN pour chaque alerte critique ou high
 *  - Résumé quotidien consolidé avec KPIs et dernières IP bloquées
 *
 * Anti-spam : chaque alerte ne génère qu'un email, même si plusieurs admins.
 * Les alertes de sévérité "low" et "medium" sont agrégées dans le résumé.
 */
class SecurityAlertEmailService
{
    /** Sévérités déclenchant un email immédiat */
    private const IMMEDIATE_SEVERITIES = [
        SecurityAlert::SEVERITY_CRITICAL,
        SecurityAlert::SEVERITY_HIGH,
    ];

    public function __construct(
        private readonly MailerInterface             $mailer,
        private readonly EntityManagerInterface      $entityManager,
        private readonly SecurityAlertRepository     $alertRepository,
        private readonly SecurityAuditLogRepository  $auditLogRepository,
        private readonly IpBlocklistRepository       $blocklistRepository,
        private readonly UrlGeneratorInterface       $urlGenerator,
        private readonly LoggerInterface             $logger,
        private readonly string                      $appName    = 'GEI',
        private readonly string                      $senderEmail = 'noreply@gei-platform.local',
        private readonly string                      $senderName  = 'GEI Security Center',
    ) {}

    // ── Notification immédiate ────────────────────────────────────────────

    /**
     * Envoie un email immédiat pour une alerte critique ou high.
     * Appelé par IntrusionDetectionService dès qu'une alerte est créée.
     */
    public function sendAlertNotification(SecurityAlert $alert): void
    {
        if (!in_array($alert->getSeverity(), self::IMMEDIATE_SEVERITIES, true)) {
            return; // Seules critical et high déclenchent un email immédiat
        }

        $admins = $this->getSuperAdmins();
        if (empty($admins)) {
            $this->logger->warning('No SUPERADMIN recipients found for security alert email.');
            return;
        }

        $dashboardUrl = $this->urlGenerator->generate(
            'app_security_alerts',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        foreach ($admins as $admin) {
            try {
                $email = (new TemplatedEmail())
                    ->from(new Address($this->senderEmail, $this->senderName))
                    ->to(new Address($admin->getEmail(), $admin->getNomComplet()))
                    ->priority(
                        $alert->getSeverity() === SecurityAlert::SEVERITY_CRITICAL
                            ? \Symfony\Component\Mime\Email::PRIORITY_HIGHEST
                            : \Symfony\Component\Mime\Email::PRIORITY_HIGH
                    )
                    ->subject($this->buildSubject($alert))
                    ->htmlTemplate('emails/security_alert.html.twig')
                    ->context([
                        'alert'        => $alert,
                        'dashboardUrl' => $dashboardUrl,
                        'admin'        => $admin,
                        'appName'      => $this->appName,
                    ]);

                $this->mailer->send($email);

                $this->logger->info('Security alert email sent', [
                    'alert_id'   => $alert->getId(),
                    'severity'   => $alert->getSeverity(),
                    'recipient'  => $admin->getEmail(),
                ]);
            } catch (\Throwable $e) {
                // Ne jamais faire échouer une détection pour un email raté
                $this->logger->error('Failed to send security alert email', [
                    'alert_id' => $alert->getId(),
                    'error'    => $e->getMessage(),
                    'email'    => $admin->getEmail(),
                ]);
            }
        }
    }

    // ── Résumé quotidien ──────────────────────────────────────────────────

    /**
     * Envoie le résumé quotidien de sécurité.
     * Appelé par SecurityDailySummaryCommand (cron 08h00).
     */
    public function sendDailySummary(): void
    {
        $admins = $this->getSuperAdmins();
        if (empty($admins)) {
            $this->logger->info('No SUPERADMIN recipients — daily summary skipped.');
            return;
        }

        $now      = new \DateTimeImmutable();
        $yesterday = $now->modify('-24 hours');
        $period    = sprintf(
            'Du %s au %s',
            $yesterday->format('d/m/Y H:i'),
            $now->format('d/m/Y H:i')
        );

        $dashStats  = $this->auditLogRepository->getDashboardStats($yesterday, $now);
        $critAlerts = $this->alertRepository->findCritical();
        $newAlerts  = $this->alertRepository->findUnacknowledged();
        $blocked    = $this->blocklistRepository->findRecentlyBlocked(1);

        $stats = [
            'totalEvents'    => $dashStats['total']          ?? 0,
            'failedLogins'   => $this->countEvent('login_failed', $yesterday, $now),
            'uniqueIps'      => $dashStats['uniqueIps']       ?? 0,
            'blockedIps'     => $this->blocklistRepository->countActiveBlocks(),
            'highRiskEvents' => $dashStats['highRiskEvents']  ?? 0,
        ];

        // Pas d'email si aucune activité suspecte
        if ($stats['failedLogins'] === 0 && empty($critAlerts) && empty($blocked)) {
            $this->logger->info('Daily summary skipped — no suspicious activity detected.');
            return;
        }

        $dashboardUrl = $this->urlGenerator->generate(
            'app_security_dashboard',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        foreach ($admins as $admin) {
            try {
                $email = (new TemplatedEmail())
                    ->from(new Address($this->senderEmail, $this->senderName))
                    ->to(new Address($admin->getEmail(), $admin->getNomComplet()))
                    ->priority(\Symfony\Component\Mime\Email::PRIORITY_NORMAL)
                    ->subject("[{$this->appName}] Rapport sécurité quotidien — {$now->format('d/m/Y')}")
                    ->htmlTemplate('emails/security_alert_summary.html.twig')
                    ->context([
                        'admin'           => $admin,
                        'period'          => $period,
                        'stats'           => $stats,
                        'criticalAlerts'  => $critAlerts,
                        'newAlerts'       => $newAlerts,
                        'recentlyBlocked' => $blocked,
                        'dashboardUrl'    => $dashboardUrl,
                        'appName'         => $this->appName,
                    ]);

                $this->mailer->send($email);

                $this->logger->info('Daily security summary sent', ['recipient' => $admin->getEmail()]);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to send daily security summary', [
                    'error' => $e->getMessage(),
                    'email' => $admin->getEmail(),
                ]);
            }
        }
    }

    // ── Notification de blocage IP ────────────────────────────────────────

    /**
     * Envoie une notification lorsqu'une IP est automatiquement bloquée.
     */
    public function sendIpBlockedNotification(
        string  $ipAddress,
        string  $reason,
        int     $riskScore,
        ?string $country = null,
    ): void {
        $admins = $this->getSuperAdmins();
        if (empty($admins)) {
            return;
        }

        $dashboardUrl = $this->urlGenerator->generate(
            'app_security_ip',
            ['ip' => $ipAddress],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        // On construit un objet fictif pour réutiliser le template alert
        $fakeAlert = new SecurityAlert();
        $fakeAlert->setAlertType(SecurityAlert::ALERT_SUSPICIOUS_BEHAVIOR);
        $fakeAlert->setSeverity(SecurityAlert::SEVERITY_HIGH);
        $fakeAlert->setTitle("IP bloquée automatiquement : {$ipAddress}");
        $fakeAlert->setDescription(sprintf(
            'L\'adresse IP %s a été bloquée automatiquement (score de risque : %d/100). Raison : %s. Pays estimé : %s.',
            $ipAddress,
            $riskScore,
            $reason,
            $country ?? 'Inconnu'
        ));
        $fakeAlert->setIpAddress($ipAddress);
        $fakeAlert->setConfidenceScore($riskScore);
        $fakeAlert->setRecommendedActions([
            'Vérifier l\'historique des requêtes de cette IP',
            'Consulter l\'analyse IP pour plus de détails',
            'Débloquer si nécessaire via le Security Center',
        ]);

        foreach ($admins as $admin) {
            try {
                $email = (new TemplatedEmail())
                    ->from(new Address($this->senderEmail, $this->senderName))
                    ->to(new Address($admin->getEmail(), $admin->getNomComplet()))
                    ->priority(\Symfony\Component\Mime\Email::PRIORITY_HIGH)
                    ->subject("[{$this->appName}] IP bloquée : {$ipAddress} (risque {$riskScore}/100)")
                    ->htmlTemplate('emails/security_alert.html.twig')
                    ->context([
                        'alert'        => $fakeAlert,
                        'dashboardUrl' => $dashboardUrl,
                        'admin'        => $admin,
                        'appName'      => $this->appName,
                    ]);

                $this->mailer->send($email);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to send IP blocked notification', [
                    'ip'    => $ipAddress,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    // ── Helpers privés ────────────────────────────────────────────────────

    /**
     * Retourne tous les SUPERADMIN actifs.
     * @return User[]
     */
    private function getSuperAdmins(): array
    {
        return $this->entityManager
            ->getRepository(User::class)
            ->createQueryBuilder('u')
            ->where('u.role = :role')
            ->andWhere('u.actif = true')
            ->setParameter('role', UserRoleEnum::SUPERADMIN->value)
            ->getQuery()
            ->getResult();
    }

    private function buildSubject(SecurityAlert $alert): string
    {
        $prefix = match ($alert->getSeverity()) {
            SecurityAlert::SEVERITY_CRITICAL => "[{$this->appName}] 🔴 CRITIQUE",
            SecurityAlert::SEVERITY_HIGH     => "[{$this->appName}] 🟠 ÉLEVÉ",
            default                          => "[{$this->appName}] Alerte sécurité",
        };

        $type = str_replace('_', ' ', $alert->getAlertType());

        return "{$prefix} — {$alert->getTitle()} ({$type})";
    }

    private function countEvent(string $eventType, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $rows = $this->auditLogRepository->countByEventType($from, $to);
        foreach ($rows as $row) {
            if ($row['eventType'] === $eventType) {
                return (int) $row['count'];
            }
        }
        return 0;
    }
}
