<?php

namespace App\Service;

use App\Dto\AuditFilterDTO;
use App\Entity\User;
use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

class AuditPdfExportService
{
    public function __construct(
        private readonly Environment $twig,
    ) {}

    public function generatePdf(AuditFilterDTO $dto, User $user, array $stats, array $results): string
    {
        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($options);

        $html = $this->twig->render('export/audit_pdf.html.twig', [
            'dto' => $dto,
            'user' => $user,
            'stats' => $stats,
            'results' => $results,
            'date_impression' => new \DateTime(),
            'platform_name' => 'Plateforme GEI',
            'platform_full' => 'Groupe d\'Échange d\'Informations',
        ]);

        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        return $dompdf->output();
    }
}
