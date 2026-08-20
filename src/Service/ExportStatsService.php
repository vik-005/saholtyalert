<?php

namespace App\Service;

use App\Service\StatistiquesService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Export Excel multi-feuilles des statistiques GEI.
 * Chaque graphique du dashboard /statistiques devient une feuille.
 */
class ExportStatsService
{
    // Couleur d'en-tête : vert institutionnel GEI
    private const COLOR_HEADER_BG   = '0F4C35';
    private const COLOR_HEADER_FONT = 'FFFFFF';
    private const COLOR_ROW_ALT     = 'F0F7F4';

    public function __construct(
        private readonly StatistiquesService $stats,
    ) {}

    public function exportStats(
        string $debut,
        string $fin,
        array  $marketIds = [],
    ): StreamedResponse {
        return new StreamedResponse(function () use ($debut, $fin, $marketIds) {
            if (ob_get_level()) {
                ob_end_clean();
            }
            $spreadsheet = $this->buildSpreadsheet($debut, $fin, $marketIds);
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => sprintf(
                'attachment; filename="Stats_GEI_%s_%s.xlsx"',
                $debut,
                $fin
            ),
            'Cache-Control'       => 'max-age=0',
        ]);
    }

    private function buildSpreadsheet(string $debut, string $fin, array $marketIds): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setCreator('Plateforme GEI')
            ->setTitle('Statistiques GEI ' . $debut . ' → ' . $fin)
            ->setSubject('Export statistiques')
            ->setDescription('Généré automatiquement par la Plateforme GEI');

        // ── Feuille 1 : Volume par pays ─────────────────────────────────────
        $sheet1 = $spreadsheet->getActiveSheet();
        $sheet1->setTitle('Volume par pays');
        $this->writeSheet(
            $sheet1,
            ['Pays', 'ISO3', 'Nombre d\'alertes'],
            array_map(
                fn($r) => [$r['pays'], $r['iso3'], (int)$r['nb']],
                $this->stats->getVolumeParPays($debut, $fin, $marketIds)
            ),
            $debut, $fin
        );

        // ── Feuille 2 : Score moyen par pays ────────────────────────────────
        $sheet2 = $spreadsheet->createSheet()->setTitle('Score moyen par pays');
        $this->writeSheet(
            $sheet2,
            ['Pays', 'ISO3', 'Score moyen GEI', 'Nombre d\'alertes scorées'],
            array_map(
                fn($r) => [$r['pays'], $r['iso3'], (float)$r['score_moyen'], (int)$r['nb']],
                $this->stats->getScoreMoyenParPays($debut, $fin, $marketIds)
            ),
            $debut, $fin
        );

        // ── Feuille 3 : Statuts par pays ────────────────────────────────────
        $sheet3 = $spreadsheet->createSheet()->setTitle('Statuts par pays');
        $this->writeSheet(
            $sheet3,
            ['Pays', 'Statut', 'Nombre'],
            array_map(
                fn($r) => [$r['pays'], $r['statut'], (int)$r['nb']],
                $this->stats->getStatutsParPays($debut, $fin, $marketIds)
            ),
            $debut, $fin
        );

        // ── Feuille 4 : Taux de transmission par pays ───────────────────────
        $sheet4 = $spreadsheet->createSheet()->setTitle('Taux transmission');
        $this->writeSheet(
            $sheet4,
            ['Pays', 'Transmises', 'Total', 'Taux (%)'],
            array_map(
                fn($r) => [$r['pays'], (int)$r['transmis'], (int)$r['total'], (float)$r['taux']],
                $this->stats->getTauxTransmissionParPays($debut, $fin, $marketIds)
            ),
            $debut, $fin
        );

        // ── Feuille 5 : Catégories ──────────────────────────────────────────
        $sheet5 = $spreadsheet->createSheet()->setTitle('Catégories');
        $this->writeSheet(
            $sheet5,
            ['Catégorie', 'Nombre d\'alertes'],
            array_map(
                fn($r) => [$r['categorie'], (int)$r['nb']],
                $this->stats->getRepartitionCategorie($debut, $fin, $marketIds)
            ),
            $debut, $fin
        );

        // ── Feuille 6 : Top Corridors ───────────────────────────────────────
        $sheet6 = $spreadsheet->createSheet()->setTitle('Top corridors');
        $this->writeSheet(
            $sheet6,
            ['Corridor', 'Nombre d\'alertes'],
            array_map(
                fn($r) => [$r['corridor'], (int)$r['nb']],
                $this->stats->getTopCorridors($debut, $fin, $marketIds, 20)
            ),
            $debut, $fin
        );

        // ── Feuille 7 : Activité agents ─────────────────────────────────────
        $sheet7 = $spreadsheet->createSheet()->setTitle('Activité agents');
        $this->writeSheet(
            $sheet7,
            ['Agent', 'Alertes soumises'],
            array_map(
                fn($r) => [$r['agent'], (int)$r['nb']],
                $this->stats->getActiviteParAgent($debut, $fin, $marketIds)
            ),
            $debut, $fin
        );

        // ── Feuille 8 : Délai moyen de traitement ───────────────────────────
        $sheet8 = $spreadsheet->createSheet()->setTitle('Délai traitement');
        $this->writeSheet(
            $sheet8,
            ['Pays', 'Délai moyen (heures)', 'Nombre d\'alertes clôturées'],
            array_map(
                fn($r) => [$r['pays'], (float)$r['delai_moyen_h'], (int)$r['nb']],
                $this->stats->getDelaiMoyenTraitement($debut, $fin, $marketIds)
            ),
            $debut, $fin
        );

        // ── Feuille 9 : Évolution temporelle ────────────────────────────────
        $evol  = $this->stats->getEvolutionTemporelle($debut, $fin, $marketIds);
        $sheet9 = $spreadsheet->createSheet()->setTitle('Évolution');
        $rows9 = [];
        foreach ($evol['labels'] as $i => $label) {
            $rows9[] = [
                $label,
                (int)($evol['soumises'][$i]   ?? 0),
                (int)($evol['transmises'][$i] ?? 0),
            ];
        }
        $this->writeSheet(
            $sheet9,
            ['Période', 'Alertes soumises', 'Alertes transmises'],
            $rows9,
            $debut, $fin
        );

        // Activer la première feuille
        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /**
     * Écrit un en-tête stylisé + les données sur une feuille donnée.
     */
    private function writeSheet(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
        array $headers,
        array $rows,
        string $debut,
        string $fin,
    ): void {
        $nbCols = count($headers);
        $lastColLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($nbCols);

        // Ligne titre
        $sheet->setCellValue('A1', 'Export GEI · ' . $sheet->getTitle() . ' · ' . $debut . ' → ' . $fin);
        $sheet->mergeCells('A1:' . $lastColLetter . '1');
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => self::COLOR_HEADER_FONT]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::COLOR_HEADER_BG]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(22);

        // En-tête colonnes
        foreach ($headers as $colIdx => $label) {
            $cell = $sheet->getCellByColumnAndRow($colIdx + 1, 2);
            $cell->setValue($label);
        }
        $sheet->getStyle('A2:' . $lastColLetter . '2')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => self::COLOR_HEADER_FONT], 'size' => 10],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1A6348']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '0D4F38']]],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(18);

        // Données
        $rowNum = 3;
        foreach ($rows as $row) {
            foreach ($row as $colIdx => $value) {
                $sheet->getCellByColumnAndRow($colIdx + 1, $rowNum)->setValue($value ?? '');
            }
            // Lignes alternées
            if ($rowNum % 2 === 0) {
                $sheet->getStyle('A' . $rowNum . ':' . $lastColLetter . $rowNum)->applyFromArray([
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::COLOR_ROW_ALT]],
                ]);
            }
            $rowNum++;
        }

        // Auto-size
        foreach (range(1, $nbCols) as $colIdx) {
            $sheet->getColumnDimensionByColumn($colIdx)->setAutoSize(true);
        }

        // Figer l'en-tête
        $sheet->freezePane('A3');

        // Filtre automatique
        if ($rowNum > 3) {
            $sheet->setAutoFilter('A2:' . $lastColLetter . ($rowNum - 1));
        }
    }
}
