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

    public function testPreservesOrderAndRemovesDuplicateImportedMarkets(): void
    {
        $marketData = [
            1 => ['nom' => 'Bénin', 'iso3' => 'BEN'],
            2 => ['nom' => 'Togo', 'iso3' => 'TGO'],
            3 => ['nom' => 'Ghana', 'iso3' => 'GHA'],
        ];
        $lookup = $this->invokePrivate('buildMarketLookup', [$marketData]);

        $path = $this->invokePrivate('resolveRouteMarkets', [
            ['TGO', 'GHA', 'TGO'],
            [],
            $lookup,
            $marketData,
            1,
        ]);

        self::assertSame([2, 3], array_column($path, 'id'));
    }

    public function testBuildsDistinctPortAndAirportMapLocationsAtIndicativeMarketCoordinates(): void
    {
        $base = [
            'principal' => 'Bénin',
            'principal_lat' => 9.3,
            'principal_lng' => 2.3,
            'type_localite' => null,
            'localite_latitude' => null,
            'localite_longitude' => null,
        ];

        $port = $this->invokePrivate('buildAlertMapLocation', [array_merge($base, [
            'type_localisation' => 'port',
            'port_corridor' => 'Port de Cotonou',
        ])]);
        $airport = $this->invokePrivate('buildAlertMapLocation', [array_merge($base, [
            'type_localisation' => 'aeroport',
            'port_corridor' => 'Aéroport de Cotonou',
        ])]);

        self::assertSame('port', $port['type']);
        self::assertSame('Port de Cotonou', $port['name']);
        self::assertFalse($port['precise']);
        self::assertSame('aeroport', $airport['type']);
        self::assertSame('Aéroport de Cotonou', $airport['name']);
        self::assertFalse($airport['precise']);
    }

    public function testLocalitiesAreKeptOutOfTheMapEvenWhenLegacyCoordinatesExist(): void
    {
        $base = [
            'principal' => 'Bénin',
            'principal_lat' => 9.3,
            'principal_lng' => 2.3,
            'type_localisation' => 'localite',
            'localite' => 'Cotonou',
            'port_corridor' => null,
        ];

        $town = $this->invokePrivate('buildAlertMapLocation', [array_merge($base, [
            'type_localite' => 'ville',
            'localite_latitude' => 6.37,
            'localite_longitude' => 2.43,
        ])]);
        $village = $this->invokePrivate('buildAlertMapLocation', [array_merge($base, [
            'type_localite' => 'village',
            'localite_latitude' => null,
            'localite_longitude' => null,
        ])]);

        self::assertNull($town);
        self::assertNull($village);
    }

    public function testDoesNotCreatePointMarkersForCorridorsOrLocationsWithoutCoordinates(): void
    {
        self::assertNull($this->invokePrivate('buildAlertMapLocation', [[
            'type_localisation' => 'corridor',
            'port_corridor' => 'Cotonou – Niamey',
        ]]));
        self::assertNull($this->invokePrivate('buildAlertMapLocation', [[
            'type_localisation' => 'port',
            'port_corridor' => 'Port',
            'principal_lat' => null,
            'principal_lng' => null,
        ]]));
    }

    private function invokePrivate(string $method, array $arguments): mixed
    {
        return (new \ReflectionMethod(CarteController::class, $method))->invoke(null, ...$arguments);
    }
}
