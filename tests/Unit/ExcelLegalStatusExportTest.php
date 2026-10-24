<?php

namespace App\Tests\Unit;

use App\Entity\Alert;
use App\Enum\AlertLegality;
use App\Service\AuditExcelExportService;
use App\Service\ExportService;
use App\Service\ExportStatsService;
use App\Service\StatistiquesService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\TestCase;

class ExcelLegalStatusExportTest extends TestCase
{
    public function testRegisterExportIncludesLegalStatusColumn(): void
    {
        $alert = new Alert();
        $alert->setDateCreation(new \DateTimeImmutable('2026-10-16'));
        $alert->setLegalStatus(AlertLegality::ILLICITE);

        $spreadsheet = $this->invokePrivate(new ExportService(), 'buildSpreadsheet', [[$alert]]);

        self::assertSame('Statut juridique', $spreadsheet->getActiveSheet()->getCell('AN1')->getValue());
        self::assertSame('Illicite', $spreadsheet->getActiveSheet()->getCell('AN2')->getValue());
    }

    public function testAuditExportIncludesLegalStatusColumn(): void
    {
        $spreadsheet = new Spreadsheet();
        $service = new AuditExcelExportService();

        $this->invokePrivate($service, 'buildDataSheet', [
            $spreadsheet,
            [['legalStatus' => 'licite']],
        ]);

        $sheet = $spreadsheet->getSheetByName('Données');
        self::assertSame('Statut juridique', $sheet->getCell('O1')->getValue());
        self::assertSame('Licite', $sheet->getCell('O2')->getValue());
    }

    public function testStatisticsExportIncludesLegalStatusCounts(): void
    {
        $stats = $this->createMock(StatistiquesService::class);
        foreach ([
            'getVolumeParPays',
            'getScoreMoyenParPays',
            'getStatutsParPays',
            'getTauxTransmissionParPays',
            'getRepartitionCategorie',
            'getTopCorridors',
            'getActiviteParAgent',
            'getDelaiMoyenTraitement',
        ] as $method) {
            $stats->method($method)->willReturn([]);
        }
        $stats->method('getEvolutionTemporelle')->willReturn([
            'labels' => [],
            'soumises' => [],
            'transmises' => [],
        ]);
        $stats->method('getLegalStatusCounts')->willReturn([
            ['legalStatus' => 'licite', 'nb' => 2],
            ['legalStatus' => 'illicite', 'nb' => 3],
            ['legalStatus' => null, 'nb' => 1],
        ]);

        $spreadsheet = $this->invokePrivate(
            new ExportStatsService($stats),
            'buildSpreadsheet',
            ['2026-01-01', '2026-12-31', []],
        );
        $sheet = $spreadsheet->getSheetByName('Statut juridique');

        self::assertSame('Statut juridique', $sheet->getCell('A2')->getValue());
        self::assertSame('Licite', $sheet->getCell('A3')->getValue());
        self::assertSame(2, $sheet->getCell('B3')->getValue());
        self::assertSame('Illicite', $sheet->getCell('A4')->getValue());
        self::assertSame(3, $sheet->getCell('B4')->getValue());
        self::assertSame('Non renseigné', $sheet->getCell('A5')->getValue());
        self::assertSame(1, $sheet->getCell('B5')->getValue());
    }

    private function invokePrivate(object $object, string $method, array $arguments): mixed
    {
        return (new \ReflectionMethod($object, $method))->invokeArgs($object, $arguments);
    }
}
