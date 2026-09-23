<?php

namespace App\Tests\Unit;

use App\Enum\TypeLocalisation;
use App\Service\ImportService;
use PHPUnit\Framework\TestCase;

class ImportTypeLocalisationDetectionTest extends TestCase
{
    public function testDetectTypeLocalisationFromText(): void
    {
        self::assertSame(TypeLocalisation::PORT, ImportService::detectTypeLocalisation('Port de Lomé'));
        self::assertSame(TypeLocalisation::CORRIDOR, ImportService::detectTypeLocalisation('Corridor Cotonou - Niamey'));
        self::assertSame(TypeLocalisation::AEROPORT, ImportService::detectTypeLocalisation('Aéroport de Cotonou'));
        self::assertSame(TypeLocalisation::PORT, ImportService::detectTypeLocalisation('Quai de Tema'));
        self::assertNull(ImportService::detectTypeLocalisation(''));
    }

    public function testSqlFallbackConditionIncludesPortPatterns(): void
    {
        $sql = ImportService::sqlTypeLocalisationFallbackCondition('port');

        self::assertStringContainsString('a.type_localisation = :typeLoc', $sql);
        self::assertStringContainsString('port|quai|terminal\\s+portuaire|portuaire|harbour|harbor', $sql);
        self::assertStringContainsString('REPLACE(LOWER(a.port_corridor)', $sql);
    }
}