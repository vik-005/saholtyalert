<?php

namespace App\Tests\Unit;

use App\Controller\CarteController;
use PHPUnit\Framework\TestCase;

class CarteParcoursResolutionTest extends TestCase
{
    public function testResolvesImportedCountriesByIso2AndNameInStoredOrder(): void
    {
        $marketData = [
            1 => ['nom' => 'Bénin', 'iso3' => 'BEN'],
            2 => ['nom' => 'Togo', 'iso3' => 'TGO'],
            3 => ['nom' => 'Côte d’Ivoire', 'iso3' => 'CIV'],
        ];

        $lookup = $this->invokePrivate('buildMarketLookup', [$marketData]);
        $path = $this->invokePrivate('resolveRouteMarkets', [
            ['TG', 'Côte d’Ivoire'],
            [],
            $lookup,
            $marketData,
            1,
        ]);

        self::assertSame([2, 3], array_column($path, 'id'));
    }

    public function testFallsBackToExistingAssociatedMarketsWhenImportedValuesAreUnknown(): void
    {
        $marketData = [
            1 => ['nom' => 'Bénin', 'iso3' => 'BEN'],
            2 => ['nom' => 'Togo', 'iso3' => 'TGO'],
        ];

        $path = $this->invokePrivate('resolveRouteMarkets', [
            ['Unknown market'],
            [['id' => 2, 'ordre' => 1]],
            [],
            $marketData,
            1,
        ]);

        self::assertSame([2], array_column($path, 'id'));
    }

    public function testResolvesIso3ValuesAndOmitsThePrincipalMarket(): void
    {
        $marketData = [
            1 => ['nom' => 'Bénin', 'iso3' => 'BEN'],
            2 => ['nom' => 'Togo', 'iso3' => 'TGO'],
        ];
        $lookup = $this->invokePrivate('buildMarketLookup', [$marketData]);

        $path = $this->invokePrivate('resolveRouteMarkets', [
            ['BEN', 'TGO'],
            [],
            $lookup,
            $marketData,
            1,
        ]);

        self::assertSame([2], array_column($path, 'id'));
    }

    private function invokePrivate(string $method, array $arguments): mixed
    {
        return (new \ReflectionMethod(CarteController::class, $method))->invoke(null, ...$arguments);
    }
}
