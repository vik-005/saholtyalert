<?php

namespace App\Service;

use App\Entity\SecurityAuditLog;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Twig\Environment;

/**
 * Service d'export du journal d'audit de sécurité.
 *
 * Formats supportés :
 *   - CSV  : StreamedResponse pour les grands volumes (jusqu'à 10 000 lignes)
 *   - PDF  : Via DomPDF (aperçu A4 paysage, max 1 000 lignes)
 */
class SecurityAuditExportService
{
    // Colonnes exportées dans l'ordre
    private const CSV_HEADERS = [
        'ID corrélation',
        'Date/Heure UTC',
        'Adresse IP',
        'Version IP',
        'Type d\'événement',
        'Utilisateur',
        'Route Symfony',
        'URL / Chemin',
        'Méthode HTTP',
        'Code HTTP',
        'Navigateur',
        'Système d\'exploitation',
        'Pays',
        'Région',
        'ISP',
        'ASN',
        'Type réseau',
        'VPN',
        'Proxy',
        'Tor',
        'Score risque',
        'Niveau risque',
        'Motif rejet',
        'Facteurs risque',
    ];

    public function __construct(
        private readonly Environment $twig,
    ) {}

    // ── Export CSV ────────────────────────────────────────────────────────

    /**
     * Génère un export CSV du journal d'audit.
     * Utilise un StreamedResponse pour ne pas charger tout en mémoire.
     *
     * @param SecurityAuditLog[] $events
     */
    public function exportCsv(array $events): StreamedResponse
    {
        $filename = sprintf('gei_security_audit_%s.csv', (new \DateTimeImmutable())->format('Y-m-d_His'));

        $response = new StreamedResponse(function () use ($events) {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                return;
            }

            // BOM UTF-8 pour Excel
            fwrite($handle, "\xEF\xBB\xBF");

            // Ligne de titre
            fputcsv($handle, [
                '# Journal d\'audit sécurité — GEI Security Center',
                '', '', '',
            ], ';');
            fputcsv($handle, [
                '# Généré le ' . (new \DateTimeImmutable())->format('d/m/Y à H:i:s') . ' UTC',
                '', '', '',
            ], ';');
            fputcsv($handle, [
                '# Total événements : ' . count($events),
                '', '', '',
            ], ';');
            fputcsv($handle, [], ';'); // ligne vide

            // En-têtes
            fputcsv($handle, self::CSV_HEADERS, ';');

            // Données
            foreach ($events as $event) {
                fputcsv($handle, $this->eventToCsvRow($event), ';');
            }

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', "attachment; filename=\"{$filename}\"");
        $response->headers->set('Cache-Control', 'no-cache, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }

    // ── Export PDF ────────────────────────────────────────────────────────

    /**
     * Génère un export PDF du journal d'audit via DomPDF.
     *
     * @param SecurityAuditLog[] $events
     */
    public function exportPdf(array $events, array $stats = []): Response
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);

        $html = $this->twig->render('export/security_audit_pdf.html.twig', [
            'events'          => array_slice($events, 0, 1000),
            'stats'           => $stats,
            'dateImpression'  => new \DateTimeImmutable(),
            'totalEvents'     => count($events),
            'truncated'       => count($events) > 1000,
        ]);

        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        $filename = sprintf('gei_security_audit_%s.pdf', (new \DateTimeImmutable())->format('Y-m-d_His'));

        $response = new Response($dompdf->output());
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', "inline; filename=\"{$filename}\"");
        $response->headers->set('Cache-Control', 'max-age=0');

        return $response;
    }

    // ── Helpers privés ────────────────────────────────────────────────────

    /**
     * Convertit un SecurityAuditLog en ligne CSV.
     */
    private function eventToCsvRow(SecurityAuditLog $event): array
    {
        return [
            $event->getCorrelationId(),
            $event->getCreatedAt()->format('Y-m-d H:i:s'),
            $event->getIpAddress(),
            str_contains($event->getIpAddress(), ':') ? 'IPv6' : 'IPv4',
            $this->labelEventType($event->getEventType()),
            $event->getUser() ? ($event->getUser()->getEmail()) : 'Anonyme',
            $event->getRouteName()     ?? '',
            $event->getUrlPath()       ?? '',
            $event->getHttpMethod()    ?? '',
            $event->getHttpStatusCode() ?? '',
            $event->getBrowserName()   ?? '',
            $event->getOsName()        ?? '',
            $event->getCountry()       ?? '',
            $event->getRegion()        ?? '',
            $event->getIsp()           ?? '',
            $event->getAsn()           ?? '',
            $event->getNetworkType()   ?? '',
            $event->isVpn()   ? 'Oui' : 'Non',
            $event->isProxy() ? 'Oui' : 'Non',
            $event->isTor()   ? 'Oui' : 'Non',
            $event->getRiskScore(),
            $this->labelRiskLevel($event->getRiskLevel()),
            $event->getRejectionReason() ?? '',
            $event->getRiskFactors() ? implode(', ', array_map(
                fn($factor, $score) => "{$factor}(+{$score})",
                array_keys($event->getRiskFactors()),
                $event->getRiskFactors()
            )) : '',
        ];
    }

    private function labelEventType(string $type): string
    {
        return match ($type) {
            SecurityAuditLog::EVENT_LOGIN_SUCCESS     => 'Connexion réussie',
            SecurityAuditLog::EVENT_LOGIN_FAILED      => 'Échec connexion',
            SecurityAuditLog::EVENT_LOGOUT            => 'Déconnexion',
            SecurityAuditLog::EVENT_UNKNOWN_ACCOUNT   => 'Compte inconnu',
            SecurityAuditLog::EVENT_FORBIDDEN_ACCESS  => 'Accès refusé',
            SecurityAuditLog::EVENT_ROUTE_ACCESSED    => 'Route consultée',
            SecurityAuditLog::EVENT_ROUTE_NOT_FOUND   => 'Route introuvable',
            SecurityAuditLog::EVENT_HTTP_ERROR        => 'Erreur HTTP',
            SecurityAuditLog::EVENT_AUTOMATED_REQUEST => 'Requête automatisée',
            SecurityAuditLog::EVENT_SENSITIVE_RESOURCE=> 'Ressource sensible',
            SecurityAuditLog::EVENT_DATA_CREATED      => 'Création donnée',
            SecurityAuditLog::EVENT_DATA_MODIFIED     => 'Modification donnée',
            SecurityAuditLog::EVENT_DATA_DELETED      => 'Suppression donnée',
            SecurityAuditLog::EVENT_IP_BLOCKED        => 'IP bloquée',
            SecurityAuditLog::EVENT_IP_UNBLOCKED      => 'IP débloquée',
            default                                   => $type,
        };
    }

    private function labelRiskLevel(string $level): string
    {
        return match ($level) {
            SecurityAuditLog::RISK_NORMAL     => 'Normal (0-30)',
            SecurityAuditLog::RISK_SUSPICIOUS => 'Suspect (31-60)',
            SecurityAuditLog::RISK_PROBABLE   => 'Probable (61-80)',
            SecurityAuditLog::RISK_THREAT     => 'Menace (81-100)',
            default                           => $level,
        };
    }
}
