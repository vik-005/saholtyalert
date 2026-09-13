<?php

namespace App\Service;

use App\Dto\AuditFilterDTO;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditExcelExportService
{
    public function export(AuditFilterDTO $dto, array $stats, array $results): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();

        // ── Feuille 1 : Résumé ─────────────────────────────────────────────
        $this->buildSummarySheet($spreadsheet, $dto, $stats);

        // ── Feuille 2 : Données filtrées ──────────────────────────────────
        $this->buildDataSheet($spreadsheet, $results);

        // ── Feuille 3 : Stats par marché ──────────────────────────────────
        $this->buildMarcheSheet($spreadsheet, $stats['parMarche'] ?? []);

        // ── Feuille 4 : Stats par catégorie ───────────────────────────────
        $this->buildCategorieSheet($spreadsheet, $stats['parCategorie'] ?? []);

        // ── Feuille 5 : Stats par statut ──────────────────────────────────
        $this->buildStatutSheet($spreadsheet, $stats['parStatut'] ?? []);

        // ── Feuille 6 : Évolution mensuelle ───────────────────────────────
        $this->buildMoisSheet($spreadsheet, $stats['parMois'] ?? []);

        $writer = new Xlsx($spreadsheet);

        $response = new StreamedResponse(function () use ($writer) {
            $writer->save('php://output');
        });

        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', sprintf(
            'attachment; filename="Audit_GEI_%s.xlsx"',
            (new \DateTime())->format('Y-m-d_His')
        ));
        $response->headers->set('Cache-Control', 'max-age=0');

        return $response;
    }

    private function buildSummarySheet(Spreadsheet $spreadsheet, AuditFilterDTO $dto, array $stats): void
    {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Résumé');

        $sheet->setCellValue('A1', 'Rapport d\'Audit GEI');
        $sheet->setCellValue('A2', 'Généré le ' . (new \DateTime())->format('d/m/Y H:i'));
        $sheet->mergeCells('A1:D1');
        $sheet->mergeCells('A2:D2');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle('A2')->getFont()->setSize(10);

        $row = 4;
        $sheet->setCellValue('A' . $row, 'Filtres appliqués');
        $sheet->getStyle('A' . $row)->getFont()->setBold(true);
        $row++;

        if ($dto->dateFrom) {
            $sheet->setCellValue('A' . $row, 'Date début');
            $sheet->setCellValue('B' . $row, $dto->dateFrom->format('d/m/Y'));
            $row++;
        }
        if ($dto->dateTo) {
            $sheet->setCellValue('A' . $row, 'Date fin');
            $sheet->setCellValue('B' . $row, $dto->dateTo->format('d/m/Y'));
            $row++;
        }
        if (!empty($dto->markets)) {
            $sheet->setCellValue('A' . $row, 'Marchés');
            $sheet->setCellValue('B' . $row, implode(', ', $dto->markets));
            $row++;
        }
        if ($dto->operateur !== '') {
            $sheet->setCellValue('A' . $row, 'Opérateur');
            $sheet->setCellValue('B' . $row, $dto->operateur);
            $row++;
        }
        if ($dto->marque !== '') {
            $sheet->setCellValue('A' . $row, 'Marque');
            $sheet->setCellValue('B' . $row, $dto->marque);
            $row++;
        }

        $row += 2;
        $sheet->setCellValue('A' . $row, 'KPI de synthèse');
        $sheet->getStyle('A' . $row)->getFont()->setBold(true);
        $row++;

        $kpi = [
            ['Total alertes', $stats['total'] ?? 0],
            ['Score moyen', $stats['scoreMoyen'] ?? '—'],
            ['Alertes critiques', $stats['critiques'] ?? 0],
            ['Taux transmission', ($stats['tauxTransmission'] ?? '—') . '%'],
        ];

        foreach ($kpi as $label => $value) {
            $sheet->setCellValue('A' . $row, $label);
            $sheet->setCellValue('B' . $row, $value);
            $row++;
        }

        $sheet->getStyle('A4:A' . ($row - 1))->getFont()->setBold(true);
    }

    private function buildDataSheet(Spreadsheet $spreadsheet, array $results): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Données');

        $headers = [
            'Code GEI', 'Pays', 'Corridor', 'Date', 'Catégorie',
            'Type', 'Priorité', 'Score', 'Statut', 'Urgence',
            'Impact', 'Exploitabilité', 'Agent', 'Résumé',
        ];

        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue($col . '1', $header);
            $sheet->getStyle($col . '1')->getFont()->setBold(true);
            $sheet->getStyle($col . '1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0F2A4A');
            $sheet->getStyle($col . '1')->getFont()->getColor()->setARGB('FFFFFFFF');
            $col++;
        }

        $rowNum = 2;
        foreach (array_slice($results, 0, 5000) as $r) {
            $col = 'A';
            $values = [
                $r['codeGei'] ?? '',
                $r['pays'] ?? '',
                $r['portCorridor'] ?? '',
                $r['dateCreation'] ?? '',
                $this->labelCategorie($r['categorie'] ?? ''),
                $this->labelTypeAlerte($r['typeAlerte'] ?? ''),
                $this->labelPriorite($r['niveau_priorite'] ?? $r['priorite'] ?? ''),
                $r['score'] ?? '',
                $this->labelStatut($r['statut'] ?? ''),
                $this->labelUrgence($r['urgence'] ?? ''),
                $this->labelImpact($r['impact'] ?? ''),
                $this->labelExploitabilite($r['exploitabilite'] ?? ''),
                $r['agent'] ?? '',
                mb_substr($r['resumeExecutif'] ?? '', 0, 200),
            ];

            foreach ($values as $value) {
                $sheet->setCellValue($col . $rowNum, $value);
                $col++;
            }
            $rowNum++;
        }

        $sheet->freezePane('A2');
    }

    private function buildMarcheSheet(Spreadsheet $spreadsheet, array $data): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Par marché');
        $sheet->setCellValue('A1', 'Marché');
        $sheet->setCellValue('B1', 'ISO3');
        $sheet->setCellValue('C1', 'Nb alertes');
        $sheet->setCellValue('D1', 'Score moyen');
        $sheet->getStyle('A1:D1')->getFont()->setBold(true);

        foreach ($data as $i => $row) {
            $sheet->setCellValue('A' . ($i + 2), $row['nom'] ?? '');
            $sheet->setCellValue('B' . ($i + 2), $row['code_iso3'] ?? '');
            $sheet->setCellValue('C' . ($i + 2), $row['nb'] ?? 0);
            $sheet->setCellValue('D' . ($i + 2), $row['score_moyen'] ?? '');
        }
    }

    private function buildCategorieSheet(Spreadsheet $spreadsheet, array $data): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Par catégorie');
        $sheet->setCellValue('A1', 'Catégorie');
        $sheet->setCellValue('B1', 'Nb');
        $sheet->getStyle('A1:B1')->getFont()->setBold(true);

        foreach ($data as $i => $row) {
            $sheet->setCellValue('A' . ($i + 2), $row['categorie'] ?? '');
            $sheet->setCellValue('B' . ($i + 2), $row['nb'] ?? 0);
        }
    }

    private function buildStatutSheet(Spreadsheet $spreadsheet, array $data): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Par statut');
        $sheet->setCellValue('A1', 'Statut');
        $sheet->setCellValue('B1', 'Nb');
        $sheet->getStyle('A1:B1')->getFont()->setBold(true);

        foreach ($data as $i => $row) {
            $sheet->setCellValue('A' . ($i + 2), $row['statut'] ?? '');
            $sheet->setCellValue('B' . ($i + 2), $row['nb'] ?? 0);
        }
    }

    private function buildMoisSheet(Spreadsheet $spreadsheet, array $data): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Évolution');
        $sheet->setCellValue('A1', 'Mois');
        $sheet->setCellValue('B1', 'Nb alertes');
        $sheet->getStyle('A1:B1')->getFont()->setBold(true);

        foreach ($data as $i => $row) {
            $sheet->setCellValue('A' . ($i + 2), $row['mois'] ?? '');
            $sheet->setCellValue('B' . ($i + 2), $row['nb'] ?? 0);
        }
    }

    private function labelCategorie(string $val): string
    {
        return $val ?: '—';
    }

    private function labelTypeAlerte(string $val): string
    {
        if ($val === '') return '—';
        try { return \App\Enum\TypeAlerte::from($val)->label(); }
        catch (\ValueError) { return $val; }
    }

    private function labelPriorite(string $val): string
    {
        if ($val === '') return '—';
        try { return \App\Enum\NiveauPriorite::from($val)->label(); }
        catch (\ValueError) { return $val; }
    }

    private function labelStatut(string $val): string
    {
        if ($val === '') return '—';
        try { return \App\Enum\AlertStatut::from($val)->label(); }
        catch (\ValueError) { return $val; }
    }

    private function labelUrgence(string $val): string
    {
        if ($val === '') return '—';
        try { return \App\Enum\AlertUrgence::from($val)->label(); }
        catch (\ValueError) { return $val; }
    }

    private function labelImpact(string $val): string
    {
        if ($val === '') return '—';
        try { return \App\Enum\AlertImpact::from($val)->label(); }
        catch (\ValueError) { return $val; }
    }

    private function labelExploitabilite(string $val): string
    {
        if ($val === '') return '—';
        try { return \App\Enum\AlertExploitabilite::from($val)->label(); }
        catch (\ValueError) { return $val; }
    }
}
