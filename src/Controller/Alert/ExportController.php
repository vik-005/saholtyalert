<?php

namespace App\Controller\Alert;

use App\Entity\Alert;
use App\Repository\AlertRepository;
use App\Service\ExportService;
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
        $pays = $request->query->get('pays');
        $statut = $request->query->get('statut');

        return $exportService->exportRegistre($pays, $statut);
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
}

