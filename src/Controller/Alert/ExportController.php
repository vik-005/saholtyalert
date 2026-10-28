<?php

namespace App\Controller\Alert;

use App\Entity\AccessLog;
use App\Entity\Alert;
use App\Entity\User;
use App\Repository\AlertRepository;
use App\Service\CryptoService;
use App\Service\ExportService;
use App\Service\ExportStatsService;
use App\Service\PdfExportService;
use App\Voter\AlertVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_PFT')]
class ExportController extends AbstractController
{
    public function __construct(
        private readonly CryptoService $crypto,
    ) {}

    private function resolveAlertToken(string $token, AlertRepository $repo): Alert
    {
        $id = $this->crypto->decryptId($token, 'alert');
        if ($id === null) {
            throw $this->createNotFoundException('Fiche d\'alerte introuvable.');
        }
        $alert = $repo->find($id);
        if (!$alert instanceof Alert) {
            throw $this->createNotFoundException('Fiche d\'alerte introuvable.');
        }
        return $alert;
    }
    /**
     * Export du registre en Excel.
     * Les filtres actifs (statut, priorité, pays, recherche…) sont transmis par l'URL
     * et appliqués via findForUser() pour respecter la portée de sécurité de chaque rôle.
     */
    #[Route('/export/registre', name: 'app_export_registre', methods: ['GET'])]
    public function exportExcel(Request $request, AlertRepository $alertRepository, ExportService $exportService, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $alerts = $alertRepository->findForUser($user, $this->extractFilters($request));

        $log = new AccessLog();
        $log->setAction(AccessLog::ACTION_EXPORT);
        $log->setUser($user);
        $log->setDetails(sprintf('Export Excel registre — %d alertes', count($alerts)));
        $em->persist($log);
        $em->flush();

        return $exportService->exportRegistre($alerts);
    }

    /**
     * Export d'une fiche individuelle en PDF via token opaque (ID non devinable).
     */
    #[Route('/alert/{token}/pdf', name: 'app_alert_pdf', methods: ['GET'], requirements: ['token' => '[A-Za-z0-9_-]+'])]
    public function exportAlertPdf(string $token, AlertRepository $alertRepository, PdfExportService $pdfService): Response
    {
        $alert = $this->resolveAlertToken($token, $alertRepository);
        $this->denyAccessUnlessGranted(AlertVoter::EXPORT, $alert);

        $pdfContent = $pdfService->generateAlertPdf($alert);

        return new Response($pdfContent, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename="Fiche_%s.pdf"', $alert->getCodeGei() ?? 'brouillon'),
        ]);
    }

    /**
     * Export du registre complet (ou filtré) en PDF.
     * Même logique de filtrage et de sécurité que l'export Excel.
     */
    #[Route('/export/registre/pdf', name: 'app_export_registre_pdf', methods: ['GET'])]
    public function exportRegistrePdf(
        Request $request,
        AlertRepository $alertRepository,
        PdfExportService $pdfService
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $alerts = $alertRepository->findForUser($user, $this->extractFilters($request));
        $pdfContent = $pdfService->generateRegistrePdf($alerts);

        return new Response($pdfContent, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename="Registre_GEI_%s.pdf"', date('Y-m-d')),
        ]);
    }

    #[Route('/export/statistiques', name: 'app_export_statistiques', methods: ['GET'])]
    public function exportStatistiques(Request $request, ExportStatsService $exportStatsService): Response
    {
        $debut     = $request->query->get('debut', (new \DateTime('-30 days'))->format('Y-m-d'));
        $fin       = $request->query->get('fin',   (new \DateTime())->format('Y-m-d'));
        $allQE       = $request->query->all();
        $marketsRawE = $allQE['markets'] ?? null;
        if (is_array($marketsRawE)) {
            $marketIds = array_values(array_filter(array_map('intval', $marketsRawE)));
        } elseif (is_string($marketsRawE) && $marketsRawE !== '') {
            $marketIds = array_values(array_filter(array_map('intval', explode(',', $marketsRawE))));
        } else {
            $marketIds = [];
        }

        return $exportStatsService->exportStats($debut, $fin, $marketIds);
    }

    /**
     * Extrait et normalise les filtres de requête compatibles avec AlertRepository::findForUser().
     */
    private function extractFilters(Request $request): array
    {
        $market = $request->query->get('market');
        if (is_array($market)) {
            $market = reset($market);
        }

        $query = $request->query->all();
        $markets = $query['markets'] ?? [];
        if (is_string($markets)) {
            $markets = $markets === '' ? [] : explode(',', $markets);
        }
        $markets = is_array($markets) ? array_values(array_filter(array_map('intval', $markets))) : [];

        return array_filter([
            'statut'         => $request->query->get('statut'),
            'niveauPriorite' => $request->query->get('priorite'),
            'market'         => $market,
            'markets'        => $markets,
            'search'         => $request->query->get('search') ?: $request->query->get('q'),
            'categorie'      => $request->query->get('categorie'),
            'urgence'        => $request->query->get('urgence'),
            'typeAlerte'     => $request->query->get('typeAlerte'),
            'typeLocalisation' => $request->query->get('type_loc'),
            'dateDebut'      => $request->query->get('debut'),
            'dateFin'        => $request->query->get('fin'),
        ], fn($v) => $v !== null && $v !== '');
    }
}

