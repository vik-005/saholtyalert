<?php

namespace App\Controller\Admin;

use App\Entity\SecurityAuditLog;
use App\Entity\SecurityAlert;
use App\Repository\SecurityAuditLogRepository;
use App\Repository\SecurityAlertRepository;
use App\Repository\IpBlocklistRepository;
use App\Repository\IpAddressReputationRepository;
use App\Service\IpBlockingService;
use App\Service\SecurityAuditExportService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/security')]
#[IsGranted('ROLE_SUPERADMIN')]
class SecurityDashboardController extends AbstractController
{
    // ── Dashboard principal ───────────────────────────────────────────────

    #[Route('/', name: 'app_security_dashboard', methods: ['GET'])]
    public function index(
        SecurityAuditLogRepository $auditLogRepo,
        SecurityAlertRepository    $alertRepo,
        IpBlocklistRepository      $blocklistRepo,
    ): Response {
        $now      = new \DateTimeImmutable();
        $weekAgo  = $now->modify('-7 days');
        $monthAgo = $now->modify('-30 days');

        try {
            $dashStats = $auditLogRepo->getDashboardStats($monthAgo, $now);
            $eventCounts = $this->buildEventCountsMap($auditLogRepo->countByEventType($monthAgo, $now));
        } catch (\Throwable) {
            $dashStats = ['total' => 0, 'uniqueIps' => 0, 'avgRiskScore' => 0.0, 'highRiskEvents' => 0];
            $eventCounts = [];
        }

        $stats = [
            'totalConnections'   => $dashStats['total']          ?? 0,
            'successfulLogins'   => $eventCounts[SecurityAuditLog::EVENT_LOGIN_SUCCESS]    ?? 0,
            'failedLogins'       => $eventCounts[SecurityAuditLog::EVENT_LOGIN_FAILED]     ?? 0,
            'unknownAccounts'    => $eventCounts[SecurityAuditLog::EVENT_UNKNOWN_ACCOUNT]  ?? 0,
            'forbiddenAccess'    => $eventCounts[SecurityAuditLog::EVENT_FORBIDDEN_ACCESS] ?? 0,
            'uniqueIps'          => $dashStats['uniqueIps']      ?? 0,
            'avgRiskScore'       => round($dashStats['avgRiskScore'] ?? 0, 1),
            'blockedIps'         => $blocklistRepo->countActiveBlocks(),
            'suspiciousAttempts' => $dashStats['highRiskEvents'] ?? 0,
            'automatedAttacks'   => $eventCounts[SecurityAuditLog::EVENT_AUTOMATED_REQUEST] ?? 0,
        ];

        // Données graphiques sur 7 jours
        try {
            $chartData = $this->buildDailyChartData($auditLogRepo, $weekAgo, $now);
            $riskData  = $auditLogRepo->countByRiskLevel($monthAgo, $now);
            $topRoutes = $this->buildTopRoutesData($auditLogRepo, $weekAgo, $now);
        } catch (\Throwable) {
            $chartData = ['dates' => [], 'logins' => [], 'failedLogins' => [], 'forbidden' => [], 'errors' => []];
            $riskData  = [];
            $topRoutes = ['labels' => [], 'values' => []];
        }

        $alertTypes = $alertRepo->countByType($monthAgo, $now);

        return $this->render('admin/security/dashboard.html.twig', [
            'stats'          => $stats,
            'recentEvents'   => $auditLogRepo->findRecent(15),
            'recentAlerts'   => $alertRepo->findRecent(10),
            'recentlyBlocked'=> $blocklistRepo->findRecentlyBlocked(7),
            'chartData'      => $chartData,
            'riskData'       => $riskData,
            'alertTypes'     => $alertTypes,
            'topRoutes'      => $topRoutes,
        ]);
    }

    // ── Journal d'audit ───────────────────────────────────────────────────

    #[Route('/audit', name: 'app_security_audit', methods: ['GET'])]
    public function audit(
        Request                    $request,
        SecurityAuditLogRepository $auditLogRepo,
    ): Response {
        $page   = max(1, $request->query->getInt('page', 1));
        $limit  = 50;
        $offset = ($page - 1) * $limit;

        $filters = $this->extractAuditFilters($request);
        $events  = $auditLogRepo->findRecent($limit, $offset, $filters);

        $eventTypes = [
            SecurityAuditLog::EVENT_LOGIN_SUCCESS    => 'Connexion réussie',
            SecurityAuditLog::EVENT_LOGIN_FAILED     => 'Échec connexion',
            SecurityAuditLog::EVENT_LOGOUT           => 'Déconnexion',
            SecurityAuditLog::EVENT_UNKNOWN_ACCOUNT  => 'Compte inconnu',
            SecurityAuditLog::EVENT_FORBIDDEN_ACCESS => 'Accès refusé',
            SecurityAuditLog::EVENT_ROUTE_NOT_FOUND  => 'Route introuvable',
            SecurityAuditLog::EVENT_HTTP_ERROR       => 'Erreur HTTP',
            SecurityAuditLog::EVENT_AUTOMATED_REQUEST=> 'Requête automatisée',
            SecurityAuditLog::EVENT_SENSITIVE_RESOURCE => 'Ressource sensible',
            SecurityAuditLog::EVENT_DATA_CREATED     => 'Création donnée',
            SecurityAuditLog::EVENT_DATA_MODIFIED    => 'Modification donnée',
            SecurityAuditLog::EVENT_DATA_DELETED     => 'Suppression donnée',
            SecurityAuditLog::EVENT_IP_BLOCKED       => 'IP bloquée',
            SecurityAuditLog::EVENT_IP_UNBLOCKED     => 'IP débloquée',
        ];

        return $this->render('admin/security/audit.html.twig', [
            'events'     => $events,
            'page'       => $page,
            'hasMore'    => count($events) === $limit,
            'filters'    => $filters,
            'eventTypes' => $eventTypes,
        ]);
    }

    // ── Export CSV ────────────────────────────────────────────────────────

    #[Route('/audit/export/csv', name: 'app_security_audit_export_csv', methods: ['GET'])]
    public function exportCsv(
        Request                    $request,
        SecurityAuditLogRepository $auditLogRepo,
        SecurityAuditExportService $exportService,
    ): Response {
        $filters = $this->extractAuditFilters($request);
        $events  = $auditLogRepo->findRecent(10000, 0, $filters);

        return $exportService->exportCsv($events);
    }

    // ── Export PDF ────────────────────────────────────────────────────────

    #[Route('/audit/export/pdf', name: 'app_security_audit_export_pdf', methods: ['GET'])]
    public function exportPdf(
        Request                    $request,
        SecurityAuditLogRepository $auditLogRepo,
        SecurityAuditExportService $exportService,
    ): Response {
        $filters = $this->extractAuditFilters($request);
        $events  = $auditLogRepo->findRecent(1000, 0, $filters);

        $now      = new \DateTimeImmutable();
        $monthAgo = $now->modify('-30 days');
        $stats    = $auditLogRepo->getDashboardStats($monthAgo, $now);

        return $exportService->exportPdf($events, $stats);
    }

    // ── Analyse des IP ────────────────────────────────────────────────────

    #[Route('/ip', name: 'app_security_ip', methods: ['GET'])]
    public function ipAnalysis(
        Request                      $request,
        IpAddressReputationRepository $reputationRepo,
        IpBlocklistRepository         $blocklistRepo,
        SecurityAuditLogRepository    $auditLogRepo,
    ): Response {
        $ipAddress    = $request->query->get('ip');
        $selectedIpData  = null;
        $selectedIpBlock = null;
        $ipAuditHistory  = [];

        if ($ipAddress) {
            $selectedIpData  = $reputationRepo->findByIpAddress($ipAddress);
            $selectedIpBlock = $blocklistRepo->findByIpAddress($ipAddress);
            $ipAuditHistory  = $auditLogRepo->findByIpAddress($ipAddress, 50);
        }

        return $this->render('admin/security/ip.html.twig', [
            'suspiciousIps'   => $reputationRepo->findHighRiskIps(50),
            'blockedIps'      => $blocklistRepo->findActiveBlocks(),
            'whitelistedIps'  => $blocklistRepo->findActiveWhitelist(),
            'selectedIp'      => $ipAddress,
            'selectedIpData'  => $selectedIpData,
            'selectedIpBlock' => $selectedIpBlock,
            'ipAuditHistory'  => $ipAuditHistory,
        ]);
    }

    #[Route('/ip/block', name: 'app_security_ip_block', methods: ['POST'])]
    public function blockIp(Request $request, IpBlockingService $blockingService): Response
    {
        $ip       = $request->request->get('ipAddress');
        $reason   = $request->request->get('reason', 'manual');
        $duration = $request->request->getInt('duration', 180);

        if ($ip) {
            $blockingService->blockIp($ip, $reason, $this->getUser(), null, $duration);
            $this->addFlash('success', "IP {$ip} bloquée pour {$duration} jours.");
        }

        return $this->redirectToRoute('app_security_ip', ['ip' => $ip]);
    }

    #[Route('/ip/unblock', name: 'app_security_ip_unblock', methods: ['POST'])]
    public function unblockIp(Request $request, IpBlockingService $blockingService): Response
    {
        $ip = $request->request->get('ipAddress');

        if ($ip) {
            $blockingService->unblockIp($ip, $this->getUser(), 'Déblocage manuel');
            $this->addFlash('success', "IP {$ip} débloquée.");
        }

        return $this->redirectToRoute('app_security_ip');
    }

    // ── Alertes ───────────────────────────────────────────────────────────

    #[Route('/alerts', name: 'app_security_alerts', methods: ['GET'])]
    public function alerts(SecurityAlertRepository $alertRepo): Response
    {
        return $this->render('admin/security/alerts.html.twig', [
            'alerts'              => $alertRepo->findRecent(100),
            'unacknowledgedCount' => count($alertRepo->findUnacknowledged()),
            'criticalCount'       => count($alertRepo->findCritical()),
        ]);
    }

    #[Route('/alert/{id}/acknowledge', name: 'app_security_alert_acknowledge', methods: ['POST'])]
    public function acknowledgeAlert(SecurityAlert $alert, EntityManagerInterface $em): Response
    {
        $alert->setStatus(SecurityAlert::STATUS_ACKNOWLEDGED);
        $alert->setAcknowledgedByAdmin($this->getUser());
        $alert->setAcknowledgedAt(new \DateTimeImmutable());
        $em->flush();

        $this->addFlash('success', 'Alerte reconnue.');
        return $this->redirectToRoute('app_security_alerts');
    }

    // ── Surveillance des routes ───────────────────────────────────────────

    #[Route('/routes', name: 'app_security_routes', methods: ['GET'])]
    public function routeMonitoring(SecurityAuditLogRepository $auditLogRepo): Response
    {
        $routes = $auditLogRepo->getRouteStats(7);

        $errorRoutes = array_filter($routes, static fn (array $stats): bool => $stats['errors'] > 0);
        $forbiddenRoutes = array_filter($routes, static fn (array $stats): bool => $stats['forbidden'] > 0);

        return $this->render('admin/security/routes.html.twig', [
            'routes' => $routes,
            'errorRoutes' => array_slice($errorRoutes, 0, 5, true),
            'forbiddenRoutes' => array_slice($forbiddenRoutes, 0, 5, true),
            'topRoutes' => array_slice($routes, 0, 5, true),
        ]);
    }

    // ── Helpers privés ────────────────────────────────────────────────────

    private function extractAuditFilters(Request $request): array
    {
        $filters = [];
        $map = ['eventType', 'ipAddress', 'riskScoreMin'];
        foreach ($map as $key) {
            if ($v = $request->query->get($key)) {
                $filters[$key] = $v;
            }
        }
        foreach (['dateFrom', 'dateTo'] as $key) {
            try {
                if ($v = $request->query->get($key)) {
                    $filters[$key] = new \DateTimeImmutable($v);
                }
            } catch (\Exception) {}
        }
        return $filters;
    }

    private function buildEventCountsMap(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $map[$row['eventType']] = (int) $row['count'];
        }
        return $map;
    }

    private function buildDailyChartData(SecurityAuditLogRepository $repo, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        // 1 seule requête SQL au lieu d'une boucle × N requêtes
        $rows = $repo->createQueryBuilder('sal')
            ->select(
                "DATE_FORMAT(sal.createdAt, '%d/%m') AS day",
                "sal.eventType",
                'COUNT(sal.id) AS cnt'
            )
            ->where('sal.createdAt BETWEEN :from AND :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('day, sal.eventType')
            ->orderBy('day', 'ASC')
            ->getQuery()
            ->getResult();

        // Construire la liste des jours
        $days = [];
        $cur = $from;
        while ($cur < $to) {
            $days[] = $cur->format('d/m');
            $cur = $cur->modify('+1 day');
        }

        $indexed = [];
        foreach ($rows as $row) {
            $indexed[$row['day']][$row['eventType']] = (int) $row['cnt'];
        }

        $data = ['dates' => $days, 'logins' => [], 'failedLogins' => [], 'forbidden' => [], 'errors' => []];
        foreach ($days as $day) {
            $d = $indexed[$day] ?? [];
            $data['logins'][]       = $d[SecurityAuditLog::EVENT_LOGIN_SUCCESS]    ?? 0;
            $data['failedLogins'][] = $d[SecurityAuditLog::EVENT_LOGIN_FAILED]     ?? 0;
            $data['forbidden'][]    = $d[SecurityAuditLog::EVENT_FORBIDDEN_ACCESS] ?? 0;
            $data['errors'][]       = $d[SecurityAuditLog::EVENT_HTTP_ERROR]       ?? 0;
        }

        return $data;
    }

    private function buildTopRoutesData(SecurityAuditLogRepository $repo, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $repo->createQueryBuilder('sal')
            ->select('sal.routeName AS route, COUNT(sal.id) AS cnt')
            ->where('sal.createdAt BETWEEN :f AND :t')
            ->setParameter('f', $from)
            ->setParameter('t', $to)
            ->groupBy('sal.routeName')
            ->orderBy('cnt', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        return [
            'labels' => array_column($rows, 'route'),
            'values' => array_map(fn($r) => (int) $r['cnt'], $rows),
        ];
    }
}
