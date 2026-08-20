<?php

namespace App\Controller\Alert;

use App\Entity\Alert;
use App\Repository\AlertRepository;
use App\Service\ExportService;
use App\Service\ExportStatsService;
use App\Service\PdfExportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class ExportController extends AbstractController
{
    #[Route('/export/registre', name: 'app_export_registre', methods: ['GET'])]
    public function exportExcel(Request $request, ExportService $exportService): Response
    {
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            throw $this->createAccessDeniedException();
        }

        $pays   = $request->query->get('pays');
        $statut = $request->query->get('statut');
        $from   = $request->query->get('from')  ? new \DateTime($request->query->get('from'))  : null;
        $to     = $request->query->get('to')    ? new \DateTime($request->query->get('to'))    : null;

        return $exportService->exportRegistre($pays, $statut, $from, $to);
    }

    #[Route('/alert/{id}/pdf', name: 'app_alert_pdf', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function exportAlertPdf(Alert $alert, PdfExportService $pdfService): Response
    {
        $pdfContent = $pdfService->generateAlertPdf($alert);

        return new Response($pdfContent, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename="Fiche_%s.pdf"', $alert->getCodeGei() ?? 'brouillon'),
        ]);
    }

    #[Route('/export/registre/pdf', name: 'app_export_registre_pdf', methods: ['GET'])]
    public function exportRegistrePdf(
        Request $request,
        AlertRepository $alertRepository,
        PdfExportService $pdfService
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            throw $this->createAccessDeniedException();
        }
        $alerts = $alertRepository->findForUser($user);
        $pdfContent = $pdfService->generateRegistrePdf($alerts);

        return new Response($pdfContent, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="Registre_GEI_%s.pdf"', date('Y-m-d')),
        ]);
    }

    #[Route('/export/statistiques', name: 'app_export_statistiques', methods: ['GET'])]
    public function exportStatistiques(Request $request, ExportStatsService $exportStatsService): Response
    {
        $debut     = $request->query->get('debut', (new \DateTime('-30 days'))->format('Y-m-d'));
        $fin       = $request->query->get('fin',   (new \DateTime())->format('Y-m-d'));
        $marketIds = array_filter(explode(',', $request->query->get('markets', '')), 'is_numeric');
        $marketIds = array_map('intval', $marketIds);

        return $exportStatsService->exportStats($debut, $fin, $marketIds);
    }
}

