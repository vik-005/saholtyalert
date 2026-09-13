<?php

namespace App\Tests\Unit;

use App\Enum\TypeLocalisation;
use PHPUnit\Framework\TestCase;

class TypeLocalisationTest extends TestCase
{
    public function testCasesAndLabels(): void
    {
        $this->assertSame('Port', TypeLocalisation::PORT->label());
        $this->assertSame('Corridor', TypeLocalisation::CORRIDOR->label());
        $this->assertSame('Aéroport', TypeLocalisation::AEROPORT->label());
    }

    public function testIcons(): void
    {
        $this->assertSame('bi-anchor', TypeLocalisation::PORT->icon());
        $this->assertSame('bi-signpost-figure', TypeLocalisation::CORRIDOR->icon());
        $this->assertSame('bi-airplane', TypeLocalisation::AEROPORT->icon());
    }
}
