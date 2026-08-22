<?php

namespace App\Service;

use App\Entity\Alert;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Service d'export du registre GEI au format .xlsx.
 * Le fichier généré est structurellement identique au registre source
 * (mêmes colonnes, même ordre — spec §5 "Export registre").
 * IMPORTANT : Les alertes doivent déjà être filtrées et sécurisées
 * (via AlertRepository::findForUser) AVANT d'appeler ce service.
 */
class ExportService
{
    // Colonnes du registre dans l'ordre exact du fichier source (Annexe B / Partie E.1)
    // Score GEI et Niveau priorité sont en COLONNES 24 et 25 (ajout plateforme, validé client)
    // NON insérés en position 12/13 comme dans l'ancienne version incorrecte.
    private const COLUMNS = [
        'ID GEI',                            // A
        'Date',                              // B
        'Émetteur (fonction/pays)',           // C
        'Zone (pays)',                        // D
        'Port / Corridor',                    // E
        'Catégorie',                          // F
        'Résumé court',                       // G
        'Type de source',                     // H
        'Anonymisation',                      // I
        'Fiabilité (A–D)',                    // J
        'Crédibilité (1–4)',                  // K
        'Urgence',                            // L
        'Impact',                             // M
        'Exploitabilité',                     // N
        'Statut',                             // O
        'Transmission (Oui / Non / À valider)', // P
        'Actions en cours',                   // Q
        'Pièces (Oui/Non + type)',            // R
        'Référence documentaire',             // S
        'Sensibilité',                        // T
        'Dernière MAJ',                       // U
        'Responsable suivi',                  // V
        'Commentaires',                       // W
        'Score GEI',                          // X — ajout plateforme (recommandé client)
        'Niveau de priorité',                 // Y — ajout plateforme (recommandé client)
    ];

    /**
     * Génère et retourne un fichier .xlsx en streaming.
     *
     * @param Alert[] $alerts Alertes pré-filtrées par le contrôleur (sécurité + filtres UI).
     */
    public function exportRegistre(array $alerts): StreamedResponse
    {
        return new StreamedResponse(function () use ($alerts) {
            // Vider tout buffer PHP en cours — évite page blanche avec PhpSpreadsheet
            if (ob_get_level()) {
                ob_end_clean();
            }
            $spreadsheet = $this->buildSpreadsheet($alerts);
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="Registre_GEI_' . date('Y-m-d') . '.xlsx"',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    private function buildSpreadsheet(array $alerts): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Registre GEI');

        // En-tête
        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0F172A']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '334155']]],
        ];

        foreach (self::COLUMNS as $col => $label) {
            $cell = $sheet->getCellByColumnAndRow($col + 1, 1);
            $cell->setValue($label);
        }
        $sheet->getStyle('A1:' . $sheet->getCellByColumnAndRow(count(self::COLUMNS), 1)->getCoordinate())->applyFromArray($headerStyle);
        $sheet->getRowDimension(1)->setRowHeight(30);

        // Données
        $rowNum = 2;
        foreach ($alerts as $alert) {
            /** @var Alert $alert */

            // Toutes les valeurs Enum doivent être converties en string
            // via ->label() ou ->value AVANT d'être passées à PhpSpreadsheet.
            $row = [
                (string)($alert->getCodeGei() ?? ''),                             // A — ID GEI
                $alert->getDateCreation()?->format('d/m/Y') ?? '',                // B — Date
                (string)($alert->getEmetteur()?->getNomComplet() ?? ''),          // C — Émetteur
                (string)($alert->getMarket()?->getNom() ?? ''),                   // D — Zone (pays)
                (string)($alert->getPortCorridor() ?? ''),                         // E — Port / Corridor
                (string)($alert->getCategorie()?->label() ?? ''),                 // F — Catégorie
                (string)($alert->getResumeExecutif() ?? ''),                      // G — Résumé court
                (string)($alert->getTypeSource()?->label() ?? ''),                // H — Type de source
                (string)($alert->getAnonymisation()?->label() ?? ''),             // I — Anonymisation (Enum→string)
                (string)($alert->getFiabiliteSource()?->value ?? ''),             // J — Fiabilité (A–D)
                (string)($alert->getCredibiliteContenu() ?? ''),                  // K — Crédibilité (1–4)
                (string)($alert->getUrgence()?->label() ?? ''),                   // L — Urgence
                (string)($alert->getImpact()?->label() ?? ''),                    // M — Impact
                (string)($alert->getExploitabilite()?->label() ?? ''),            // N — Exploitabilité
                (string)($alert->getStatut()?->label() ?? ''),                    // O — Statut
                (string)($alert->getTransmission()?->label() ?? ''),              // P — Transmission (Enum→string)
                (string)($alert->getActionsEnCours() ?? ''),                      // Q — Actions en cours
                $alert->isPiecesDisponibles() ? 'Oui' : 'Non',                    // R — Pièces
                (string)($alert->getReferenceDocumentaire() ?? ''),               // S — Référence documentaire
                (string)($alert->getSensibilite()?->label() ?? ''),               // T — Sensibilité (Enum→string)
                $alert->getUpdatedAt()?->format('d/m/Y H:i') ?? '',               // U — Dernière MAJ
                (string)($alert->getResponsableSuivi()?->getNomComplet() ?? ''),  // V — Responsable suivi
                (string)($alert->getCommentaires() ?? ''),                        // W — Commentaires
                (string)($alert->getEffectiveScore() ?? ''),                      // X — Score GEI
                (string)($alert->getEffectiveNiveauPriorite()?->label() ?? ''),   // Y — Niveau priorité
            ];

            foreach ($row as $colIdx => $value) {
                $sheet->getCellByColumnAndRow($colIdx + 1, $rowNum)->setValue($value ?? '');
            }

            // Couleur de ligne selon priorité
            $priorityColor = match($alert->getNiveauPriorite()?->value) {
                'critique' => 'FEE2E2',
                'eleve' => 'FEF3C7',
                'modere' => 'FFF7ED',
                default => 'F8FAFC',
            };

            $lastCol = $sheet->getCellByColumnAndRow(count(self::COLUMNS), $rowNum)->getCoordinate();
            $sheet->getStyle('A' . $rowNum . ':' . $lastCol)->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $priorityColor]],
            ]);

            $rowNum++;
        }

        // Auto-size colonnes
        foreach (range(1, count(self::COLUMNS)) as $colIdx) {
            $sheet->getColumnDimensionByColumn($colIdx)->setAutoSize(true);
        }

        // Figer l'en-tête
        $sheet->freezePane('A2');

        // Filtre automatique
        $lastCell = $sheet->getCellByColumnAndRow(count(self::COLUMNS), 1)->getCoordinate();
        $sheet->setAutoFilter('A1:' . $lastCell);

        return $spreadsheet;
    }
}
