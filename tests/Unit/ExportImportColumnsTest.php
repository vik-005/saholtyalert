<?php

namespace App\Tests\Unit;

use App\Service\ExportService;
use PHPUnit\Framework\TestCase;

class ExportImportColumnsTest extends TestCase
{
    public function testExportServiceColumnsCount(): void
    {
        $columns = ExportService::COLUMNS;
        $this->assertCount(39, $columns);
        $this->assertContains('Hypothèses analytiques', $columns);
        $this->assertContains('Éléments factuels', $columns);
        $this->assertContains('Marque', $columns);
        $this->assertContains('Opérateur / Acteur', $columns);
        $this->assertContains('Type d\'alerte', $columns);
        $this->assertContains('Type de localisation', $columns);
        $this->assertContains('Recommandation', $columns);
        $this->assertContains('Historique de la source', $columns);
        $this->assertContains('Décision GEI', $columns);
        $this->assertContains('Parcours pays', $columns);
    }
}
