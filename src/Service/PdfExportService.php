<?php

namespace App\Service;

use App\Entity\Alert;
use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

/**
 * Service de génération de rapports PDF (Dompdf).
 * Permet de télécharger une Fiche d'Alerte individuelle ou le Registre complet en PDF.
 */
class PdfExportService
{
    public function __construct(
        private readonly Environment $twig,
    ) {}

    public function generateAlertPdf(Alert $alert): string
    {
        $options = new Options();
        $options->set('defaultFont', 'Helvetica');
        $options->set('isRemoteEnabled', true);

        $dompdf = new Dompdf($options);

        $html = $this->twig->render('export/alert_pdf.html.twig', [
            'alert' => $alert,
            'date_impression' => new \DateTime(),
        ]);

        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    public function generateRegistrePdf(array $alerts): string
    {
        $options = new Options();
        $options->set('defaultFont', 'Helvetica');
        $options->set('isRemoteEnabled', true);

        $dompdf = new Dompdf($options);

        $html = $this->twig->render('export/registre_pdf.html.twig', [
            'alerts' => $alerts,
            'date_impression' => new \DateTime(),
        ]);

        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        return $dompdf->output();
    }
}
