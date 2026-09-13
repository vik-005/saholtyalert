<?php

namespace App\Tests\Unit;

use App\Dto\CorridorFilterDTO;
use App\Service\StatistiquesService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Tests unitaires validant la logique de filtrage hiérarchique Marché -> Type
 * et la cohérence des données statistiques pour la page Corridors/Ports/Aéroports (Spec Partie C).
 */
class CorridorStatisticsTest extends TestCase
{
    private $em;
    private $conn;
    private $cache;
    private $service;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->conn = $this->createMock(Connection::class);
        $this->cache = $this->createMock(CacheInterface::class);

        $this->em->expects($this->any())
            ->method('getConnection')
            ->willReturn($this->conn);

        // Mock du cache sans latence
        $this->cache->expects($this->any())
            ->method('get')
            ->willReturnCallback(function ($key, $callback = null) {
                if ($callback === null) {
                    return null;
                }
                $item = $this->createMock(ItemInterface::class);
                $item->method('expiresAfter')->willReturn($item);
                return $callback($item);
            });

        $this->service = new StatistiquesService($this->em, $this->cache);
    }

    /**
     * Test C.1 : Filtre Marché seul
     * Décocher tous les marchés sauf un (ex: Bénin ID=1) -> 100% des résultats affichés
     * (graphique, volume, tableau) doivent appartenir exclusivement à ce marché.
     */
    public function testFiltreMarcheSeul(): void
    {
        $dto = new CorridorFilterDTO();
        $dto->dateDebut = '2026-01-01';
        $dto->dateFin = '2026-03-31';
        $dto->marketIds = [1]; // Bénin uniquement
        $dto->typeLoc = null;  // Tous

        $this->conn->expects($this->exactly(4))
            ->method('fetchAllAssociative')
            ->will($this->returnCallback(function ($sql, $params) {
                // Vérifier que la condition SQL filtre strictement sur le marché 1
                $this->assertStringContainsString('a.market_id IN (1)', $sql);

                // Simulation des résultats pour le marché 1
                if (str_contains($sql, 'a.port_corridor AS corridor') || str_contains($sql, 'a.port_corridor                           AS corridor')) {
                    return [
                        ['corridor' => 'Port de Cotonou', 'type' => 'port', 'pays' => 'Bénin', 'iso3' => 'BEN', 'nb' => 14],
                        ['corridor' => 'Corridor Cotonou-Niamey', 'type' => 'corridor', 'pays' => 'Bénin', 'iso3' => 'BEN', 'nb' => 6],
                    ];
                }
                if (str_contains($sql, 'SELECT m.nom AS pays')) {
                    return [
                        ['pays' => 'Bénin', 'iso3' => 'BEN', 'nb' => 20],
                    ];
                }
                return [];
            }));

        $dashboard = $this->service->getCorridorDashboard($dto);

        $this->assertTrue($dashboard['has_data']);
        $this->assertNotEmpty($dashboard['top_localisations']);
        $this->assertNotEmpty($dashboard['volume_par_pays']);

        // 100% des résultats appartiennent au Bénin
        foreach ($dashboard['top_localisations'] as $loc) {
            $this->assertEquals('Bénin', $loc['pays']);
            $this->assertEquals('BEN', $loc['iso3']);
        }
        foreach ($dashboard['volume_par_pays'] as $vol) {
            $this->assertEquals('Bénin', $vol['pays']);
            $this->assertEquals('BEN', $vol['iso3']);
        }
    }

    /**
     * Test C.2 : Filtre Type = Port seul
     * Vérifier que le classement affiché ne contient que des entrées de type Port,
     * avec les bons noms, et que le volume par pays ne compte que les alertes de type Port.
     */
    public function testFiltreTypePortSeul(): void
    {
        $dto = new CorridorFilterDTO();
        $dto->dateDebut = '2026-01-01';
        $dto->dateFin = '2026-03-31';
        $dto->marketIds = [1, 2];
        $dto->typeLoc = 'port';

        $this->conn->expects($this->exactly(4))
            ->method('fetchAllAssociative')
            ->will($this->returnCallback(function ($sql, $params) {
                // Doit filtrer sur typeLoc = 'port' pour le classement, le volume par pays et les KPIs
                if (str_contains($sql, 'a.port_corridor AS corridor') || str_contains($sql, 'a.port_corridor                           AS corridor') || str_contains($sql, 'SELECT m.nom AS pays')) {
                    $this->assertStringContainsString('a.type_localisation = :typeLoc', $sql);
                    $this->assertEquals('port', $params['typeLoc']);
                }

                if (str_contains($sql, 'a.port_corridor AS corridor') || str_contains($sql, 'a.port_corridor                           AS corridor')) {
                    return [
                        ['corridor' => 'Port de Cotonou', 'type' => 'port', 'pays' => 'Bénin', 'iso3' => 'BEN', 'nb' => 12],
                        ['corridor' => 'Port Autonome de Lomé', 'type' => 'port', 'pays' => 'Togo', 'iso3' => 'TGO', 'nb' => 9],
                    ];
                }
                if (str_contains($sql, 'SELECT m.nom AS pays')) {
                    return [
                        ['pays' => 'Bénin', 'iso3' => 'BEN', 'nb' => 12],
                        ['pays' => 'Togo', 'iso3' => 'TGO', 'nb' => 9],
                    ];
                }
                return [];
            }));

        $dashboard = $this->service->getCorridorDashboard($dto);

        $this->assertTrue($dashboard['has_data']);
        $this->assertCount(2, $dashboard['top_localisations']);
        foreach ($dashboard['top_localisations'] as $item) {
            $this->assertEquals('port', $item['type']);
            $this->assertStringStartsWith('Port', $item['corridor']);
        }

        $this->assertEquals(21, array_sum(array_column($dashboard['volume_par_pays'], 'nb')));
    }

    /**
     * Test C.3 : Combinaison Marché + Type
     * Un marché précis (ex: Togo ID=2) + Type = Corridor -> intersection exacte.
     */
    public function testCombinaisonMarcheEtType(): void
    {
        $dto = new CorridorFilterDTO();
        $dto->dateDebut = '2026-01-01';
        $dto->dateFin = '2026-03-31';
        $dto->marketIds = [2]; // Togo
        $dto->typeLoc = 'corridor';

        $this->conn->expects($this->exactly(4))
            ->method('fetchAllAssociative')
            ->will($this->returnCallback(function ($sql, $params) {
                $this->assertStringContainsString('a.market_id IN (2)', $sql);
                if (str_contains($sql, 'a.port_corridor AS corridor') || str_contains($sql, 'a.port_corridor                           AS corridor') || str_contains($sql, 'SELECT m.nom AS pays')) {
                    $this->assertStringContainsString('a.type_localisation = :typeLoc', $sql);
                    $this->assertEquals('corridor', $params['typeLoc']);
                }

                if (str_contains($sql, 'a.port_corridor AS corridor') || str_contains($sql, 'a.port_corridor                           AS corridor')) {
                    return [
                        ['corridor' => 'Corridor Lomé-Ouagadougou', 'type' => 'corridor', 'pays' => 'Togo', 'iso3' => 'TGO', 'nb' => 7],
                    ];
                }
                if (str_contains($sql, 'SELECT m.nom AS pays')) {
                    return [
                        ['pays' => 'Togo', 'iso3' => 'TGO', 'nb' => 7],
                    ];
                }
                return [];
            }));

        $dashboard = $this->service->getCorridorDashboard($dto);

        $this->assertTrue($dashboard['has_data']);
        $this->assertCount(1, $dashboard['top_localisations']);
        $this->assertEquals('Corridor Lomé-Ouagadougou', $dashboard['top_localisations'][0]['corridor']);
        $this->assertEquals('Togo', $dashboard['top_localisations'][0]['pays']);
        $this->assertEquals(7, $dashboard['volume_par_pays'][0]['nb']);
    }

    /**
     * Test C.4 : Entrée sans donnée
     * Vérifier explicitement qu'un marché ou un port sans aucune alerte n'apparaît dans aucun bloc
     * et que les requêtes comportent la clause native HAVING COUNT > 0.
     */
    public function testEntreeSansDonneeExclue(): void
    {
        $dto = new CorridorFilterDTO();
        $dto->dateDebut = '2026-01-01';
        $dto->dateFin = '2026-03-31';
        $dto->marketIds = [1, 2, 3]; // 3 marchés
        $dto->typeLoc = null;

        $this->conn->expects($this->exactly(4))
            ->method('fetchAllAssociative')
            ->will($this->returnCallback(function ($sql, $params) {
                // La requête doit nativement exclure les zéros via HAVING COUNT > 0
                $this->assertStringContainsString('HAVING COUNT(a.id) > 0', $sql);

                // Même si le marché 3 (ex: Niger) est sélectionné, s'il a 0 alerte, la BD ne retourne que Bénin et Togo
                if (str_contains($sql, 'SELECT m.nom AS pays')) {
                    return [
                        ['pays' => 'Bénin', 'iso3' => 'BEN', 'nb' => 5],
                        ['pays' => 'Togo', 'iso3' => 'TGO', 'nb' => 3],
                    ];
                }
                if (str_contains($sql, 'a.port_corridor AS corridor') || str_contains($sql, 'a.port_corridor                           AS corridor')) {
                    return [
                        ['corridor' => 'Port de Cotonou', 'type' => 'port', 'pays' => 'Bénin', 'iso3' => 'BEN', 'nb' => 5],
                        ['corridor' => 'Port de Lomé', 'type' => 'port', 'pays' => 'Togo', 'iso3' => 'TGO', 'nb' => 3],
                    ];
                }
                return [];
            }));

        $dashboard = $this->service->getCorridorDashboard($dto);

        // Vérification qu'aucune ligne à 0 n'existe dans les résultats
        foreach ($dashboard['top_localisations'] as $item) {
            $this->assertGreaterThan(0, $item['nb']);
        }
        foreach ($dashboard['volume_par_pays'] as $vol) {
            $this->assertGreaterThan(0, $vol['nb']);
            $this->assertNotEquals('Niger', $vol['pays']);
        }
    }

    /**
     * Test C.5 : Période ou sélection sans aucune donnée
     * Remplacement de l'ensemble des blocs par un état vide unique (has_data = false),
     * sans erreur technique.
     */
    public function testPeriodeSansDonneeEtatVide(): void
    {
        $dto = new CorridorFilterDTO();
        $dto->dateDebut = '2020-01-01';
        $dto->dateFin = '2020-01-31';
        $dto->marketIds = [1, 2];
        $dto->typeLoc = null;

        $this->conn->expects($this->exactly(4))
            ->method('fetchAllAssociative')
            ->willReturn([]); // Aucune alerte en base

        $dashboard = $this->service->getCorridorDashboard($dto);

        $this->assertFalse($dashboard['has_data'], 'has_data doit valoir false pour déclencher l\'état vide unique');
        $this->assertEmpty($dashboard['top_localisations']);
        $this->assertEmpty($dashboard['volume_par_pays']);
        $this->assertEquals(0, $dashboard['kpis']['total']);
    }

    /**
     * Test C.5 bis : Tous les marchés décochés
     * Doit court-circuiter immédiatement sans requête et renvoyer has_data = false.
     */
    public function testTousMarchesDecochesEtatVide(): void
    {
        $dto = new CorridorFilterDTO();
        $dto->dateDebut = '2026-01-01';
        $dto->dateFin = '2026-01-31';
        $dto->marketIds = []; // Aucun marché sélectionné
        $dto->typeLoc = null;

        // Aucune requête DB ne doit être exécutée
        $this->conn->expects($this->never())->method('fetchAllAssociative');

        $dashboard = $this->service->getCorridorDashboard($dto);

        $this->assertFalse($dashboard['has_data']);
        $this->assertEmpty($dashboard['top_localisations']);
        $this->assertEmpty($dashboard['volume_par_pays']);
    }

    /**
     * Test C.6 : Cohérence croisée
     * Le total du panneau « Volume par pays » doit TOUJOURS être égal à la somme
     * des barres du graphique principal quand Type = Tous.
     */
    public function testCoherenceCroiseeVolumeEtTop(): void
    {
        $dto = new CorridorFilterDTO();
        $dto->dateDebut = '2026-01-01';
        $dto->dateFin = '2026-03-31';
        $dto->marketIds = [1, 2];
        $dto->typeLoc = null; // Type = Tous

        $localisations = [
            ['corridor' => 'Port de Cotonou', 'type' => 'port', 'pays' => 'Bénin', 'iso3' => 'BEN', 'nb' => 15],
            ['corridor' => 'Corridor Cotonou-Niamey', 'type' => 'corridor', 'pays' => 'Bénin', 'iso3' => 'BEN', 'nb' => 8],
            ['corridor' => 'Port de Lomé', 'type' => 'port', 'pays' => 'Togo', 'iso3' => 'TGO', 'nb' => 12],
            ['corridor' => 'Aéroport de Lomé', 'type' => 'aeroport', 'pays' => 'Togo', 'iso3' => 'TGO', 'nb' => 4],
        ];

        $volume = [
            ['pays' => 'Bénin', 'iso3' => 'BEN', 'nb' => 23], // 15 + 8 = 23
            ['pays' => 'Togo', 'iso3' => 'TGO', 'nb' => 16],  // 12 + 4 = 16
        ];

        $this->conn->expects($this->exactly(4))
            ->method('fetchAllAssociative')
            ->will($this->returnCallback(function ($sql) use ($localisations, $volume) {
                if (str_contains($sql, 'a.port_corridor AS corridor') || str_contains($sql, 'a.port_corridor                           AS corridor')) {
                    return $localisations;
                }
                if (str_contains($sql, 'SELECT m.nom AS pays')) {
                    return $volume;
                }
                return [];
            }));

        $dashboard = $this->service->getCorridorDashboard($dto);

        $totalBarresGraphique = array_sum(array_column($dashboard['top_localisations'], 'nb'));
        $totalVolumeParPays   = array_sum(array_column($dashboard['volume_par_pays'], 'nb'));

        $this->assertEquals(39, $totalBarresGraphique);
        $this->assertEquals(39, $totalVolumeParPays);
        $this->assertEquals(
            $totalVolumeParPays,
            $totalBarresGraphique,
            'Le total du panneau Volume par pays doit être strictement égal à la somme des barres du graphique quand Type = Tous'
        );
    }
}
