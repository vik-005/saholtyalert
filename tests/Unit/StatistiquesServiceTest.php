<?php

namespace App\Tests\Unit;

use App\Service\StatistiquesService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Tests unitaires de StatistiquesService.
 * 
 * Couvre :
 *  1. getVolumeParPays — volume d'alertes par pays
 *  2. getScoreMoyenParPays — score moyen par pays
 *  3. getStatutsParPays — statuts empilés par pays
 *  4. getTauxTransmissionParPays — taux de transmission par pays
 *  5. getTauxSla — jauge SLA 72h
 *  6. getTauxExploitables — jauge exploitabilité
 *  7. getRepartitionCategorie — répartition par catégorie
 *  8. getTopCorridors — top corridors
 *  9. getStatsByLocalisationType — Port vs Corridor
 *  10. getHeatmapFiabiliteCredibilite — matrice 4×4
 *  11. getActiviteParAgent — activité par agent
 *  12. getDelaiMoyenTraitement — délai par pays
 *  13. getEvolutionTemporelle — evolution temporelle
 *  14. invalidateAll — invalidation cache
 */
class StatistiquesServiceTest extends TestCase
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

        // Configure le cache pour ne pas utiliser le callback (retourne directement la valeur)
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

    // ─────────────────────────────────────────────────────────────────────────
    // 1. getVolumeParPays
    // ─────────────────────────────────────────────────────────────────────────

    public function testGetVolumeParPays(): void
    {
        $rows = [
            ['pays' => 'Bénin', 'iso3' => 'BEN', 'nb' => 12],
            ['pays' => 'Togo', 'iso3' => 'TGO', 'nb' => 8],
        ];

        $this->conn->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn($rows);

        $result = $this->service->getVolumeParPays('2026-01-01', '2026-01-31', [1, 2]);

        $this->assertCount(2, $result);
        $this->assertEquals('Bénin', $result[0]['pays']);
        $this->assertEquals(12, $result[0]['nb']);
    }

    public function testGetVolumeParPaysSansFiltreMarket(): void
    {
        $this->conn->expects($this->once())
            ->method('fetchAllAssociative')
            ->will($this->returnCallback(function ($sql) {
                $this->assertStringNotContainsString('a.market_id IN', $sql);
                return [];
            }));

        $this->service->getVolumeParPays('2026-01-01', '2026-01-31', []);
    }

    public function testGetVolumeParPaysAvecCache(): void
    {
        // Pour le test du cache, on doit utiliser des clés différentes
        // Pour éviter le cache, on utilise des dates différentes
        $rows = [['pays' => 'Bénin', 'iso3' => 'BEN', 'nb' => 12]];

        $this->conn->expects($this->exactly(2))
            ->method('fetchAllAssociative')
            ->willReturn($rows);

        // Deux appels avec dates différentes — pas de cache hit
        $this->service->getVolumeParPays('2026-01-01', '2026-01-31', [1]);
        $this->service->getVolumeParPays('2026-02-01', '2026-02-28', [1]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. getScoreMoyenParPays
    // ─────────────────────────────────────────────────────────────────────────

    public function testGetScoreMoyenParPays(): void
    {
        $rows = [
            ['pays' => 'Bénin', 'iso3' => 'BEN', 'score_moyen' => 17.3, 'nb' => 12],
            ['pays' => 'Togo', 'iso3' => 'TGO', 'score_moyen' => 15.8, 'nb' => 8],
        ];

        $this->conn->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn($rows);

        $result = $this->service->getScoreMoyenParPays('2026-01-01', '2026-01-31', [1]);

        $this->assertCount(2, $result);
        $this->assertEquals(17.3, $result[0]['score_moyen']);
        $this->assertEquals('Bénin', $result[0]['pays']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. getStatutsParPays
    // ─────────────────────────────────────────────────────────────────────────

    public function testGetStatutsParPays(): void
    {
        $rows = [
            ['pays' => 'Bénin', 'statut' => 'transmis', 'nb' => 5],
            ['pays' => 'Bénin', 'statut' => 'qualifiee', 'nb' => 3],
            ['pays' => 'Togo', 'statut' => 'transmis', 'nb' => 2],
        ];

        $this->conn->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn($rows);

        $result = $this->service->getStatutsParPays('2026-01-01', '2026-01-31', [1]);

        $this->assertCount(3, $result);
        $this->assertEquals('transmis', $result[0]['statut']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4. getTauxTransmissionParPays
    // ─────────────────────────────────────────────────────────────────────────

    public function testGetTauxTransmissionParPays(): void
    {
        $rows = [
            ['pays' => 'Bénin', 'transmis' => 5, 'total' => 6],
            ['pays' => 'Togo', 'transmis' => 3, 'total' => 4],
        ];

        $this->conn->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn($rows);

        $result = $this->service->getTauxTransmissionParPays('2026-01-01', '2026-01-31', [1]);

        $this->assertEquals(83.3, $result[0]['taux']); // 5/6 * 100
        $this->assertEquals(75.0, $result[1]['taux']); // 3/4 * 100
    }

    public function testGetTauxTransmissionParPaysTotalZero(): void
    {
        $rows = [['pays' => 'Bénin', 'transmis' => 0, 'total' => 0]];

        $this->conn->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn($rows);

        $result = $this->service->getTauxTransmissionParPays('2026-01-01', '2026-01-31', [1]);

        $this->assertEquals(0.0, $result[0]['taux']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 5. getTauxSla
    // ─────────────────────────────────────────────────────────────────────────

    public function testGetTauxSla(): void
    {
        $row = [
            'respectees' => 8,
            'cloturees' => 8,
            'total' => 10,
        ];

        $this->conn->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn($row);

        $result = $this->service->getTauxSla('2026-01-01', '2026-01-31');

        $this->assertEquals(100.0, $result['taux']); // 8/8 * 100
        $this->assertEquals('gauge-green', $result['color_class']);
        $this->assertEquals(8, $result['respectees']);
        $this->assertEquals(8, $result['cloturees']);
        $this->assertEquals(10, $result['total']);
    }

    public function testGetTauxSlaSansCloturee(): void
    {
        $row = [
            'respectees' => 0,
            'cloturees' => 0,
            'total' => 0,
        ];

        $this->conn->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn($row);

        $result = $this->service->getTauxSla('2026-01-01', '2026-01-31');

        $this->assertNull($result['taux']);
        $this->assertEquals('gauge-gray', $result['color_class']);
    }

    public function testGetTauxSlaNiveauAmber(): void
    {
        $row = [
            'respectees' => 6,
            'cloturees' => 8,
            'total' => 10,
        ];

        $this->conn->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn($row);

        $result = $this->service->getTauxSla('2026-01-01', '2026-01-31');

        $this->assertEquals(75.0, $result['taux']);
        $this->assertEquals('gauge-amber', $result['color_class']);
    }

    public function testGetTauxSlaNiveauRed(): void
    {
        $row = [
            'respectees' => 5,
            'cloturees' => 8,
            'total' => 10,
        ];

        $this->conn->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn($row);

        $result = $this->service->getTauxSla('2026-01-01', '2026-01-31');

        $this->assertEquals(62.5, $result['taux']);
        $this->assertEquals('gauge-red', $result['color_class']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 6. getTauxExploitables
    // ─────────────────────────────────────────────────────────────────────────

    public function testGetTauxExploitables(): void
    {
        $row = [
            'actionnables' => 6,
            'total' => 10,
        ];

        $this->conn->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn($row);

        $result = $this->service->getTauxExploitables('2026-01-01', '2026-01-31', [1]);

        $this->assertEquals(60.0, $result['taux']);
        $this->assertEquals('gauge-green', $result['color_class']);
    }

    public function testGetTauxExploitablesNiveauAmber(): void
    {
        $row = [
            'actionnables' => 5,
            'total' => 10,
        ];

        $this->conn->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn($row);

        $result = $this->service->getTauxExploitables('2026-01-01', '2026-01-31', [1]);

        $this->assertEquals(50.0, $result['taux']);
        $this->assertEquals('gauge-amber', $result['color_class']);
    }

    public function testGetTauxExploitablesNiveauRed(): void
    {
        $row = [
            'actionnables' => 3,
            'total' => 10,
        ];

        $this->conn->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn($row);

        $result = $this->service->getTauxExploitables('2026-01-01', '2026-01-31', [1]);

        $this->assertEquals(30.0, $result['taux']);
        $this->assertEquals('gauge-red', $result['color_class']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 7. getRepartitionCategorie
    // ─────────────────────────────────────────────────────────────────────────

    public function testGetRepartitionCategorie(): void
    {
        $rows = [
            ['categorie' => 'Contrebande', 'nb' => 15],
            ['categorie' => 'Droit de douane', 'nb' => 8],
            ['categorie' => 'autre', 'nb' => 2],
        ];

        $this->conn->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn($rows);

        $result = $this->service->getRepartitionCategorie('2026-01-01', '2026-01-31', [1]);

        $this->assertCount(3, $result);
        $this->assertEquals('Contrebande', $result[0]['categorie']);
        $this->assertEquals(15, $result[0]['nb']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 8. getTopCorridors
    // ─────────────────────────────────────────────────────────────────────────

    public function testGetTopCorridors(): void
    {
        $rows = [
            ['corridor' => 'Port de Cotonou', 'nb' => 12],
            ['corridor' => 'Corridor Abomey', 'nb' => 8],
        ];

        $this->conn->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn($rows);

        $result = $this->service->getTopCorridors('2026-01-01', '2026-01-31', [1], 10, 'port');

        $this->assertCount(2, $result);
        $this->assertEquals('Port de Cotonou', $result[0]['corridor']);
    }

    public function testGetTopCorridorsSansFiltreTypeLocalisation(): void
    {
        $this->conn->expects($this->once())
            ->method('fetchAllAssociative')
            ->will($this->returnCallback(function ($sql, $params) {
                $this->assertStringNotContainsString('type_localisation', $sql);
                return [];
            }));

        $this->service->getTopCorridors('2026-01-01', '2026-01-31', [1], 10, null);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 9. getStatsByLocalisationType
    // ─────────────────────────────────────────────────────────────────────────

    public function testGetStatsByLocalisationType(): void
    {
        $rows = [
            ['type' => 'port', 'nb' => 12],
            ['type' => 'corridor', 'nb' => 8],
        ];

        $this->conn->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn($rows);

        $result = $this->service->getStatsByLocalisationType('2026-01-01', '2026-01-31', [1]);

        $this->assertCount(2, $result);
        $this->assertEquals('port', $result[0]['type']);
        $this->assertEquals(12, $result[0]['nb']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 10. getHeatmapFiabiliteCredibilite
    // ─────────────────────────────────────────────────────────────────────────

    public function testGetHeatmapFiabiliteCredibilite(): void
    {
        $rows = [
            ['fiabilite' => 'A', 'credibilite' => 1, 'nb' => 5],
            ['fiabilite' => 'A', 'credibilite' => 2, 'nb' => 3],
            ['fiabilite' => 'B', 'credibilite' => 1, 'nb' => 2],
        ];

        $this->conn->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn($rows);

        $result = $this->service->getHeatmapFiabiliteCredibilite('2026-01-01', '2026-01-31', [1]);

        // Vérifier la structure matricielle
        $this->assertArrayHasKey('A', $result);
        $this->assertArrayHasKey(1, $result['A']);
        $this->assertEquals(5, $result['A'][1]);
        $this->assertEquals(3, $result['A'][2]);
        $this->assertEquals(2, $result['B'][1]);
        $this->assertEquals(0, $result['A'][3]); // Default value
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 11. getActiviteParAgent
    // ─────────────────────────────────────────────────────────────────────────

    public function testGetActiviteParAgent(): void
    {
        $rows = [
            ['agent' => 'Jean Dossou', 'nb' => 15],
            ['agent' => 'Marie Koumantou', 'nb' => 12],
        ];

        $this->conn->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn($rows);

        $result = $this->service->getActiviteParAgent('2026-01-01', '2026-01-31', [1]);

        $this->assertCount(2, $result);
        $this->assertEquals('Jean Dossou', $result[0]['agent']);
        $this->assertEquals(15, $result[0]['nb']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 12. getDelaiMoyenTraitement
    // ─────────────────────────────────────────────────────────────────────────

    public function testGetDelaiMoyenTraitement(): void
    {
        $rows = [
            ['pays' => 'Bénin', 'delai_moyen_h' => 24.5, 'nb' => 10],
            ['pays' => 'Togo', 'delai_moyen_h' => 18.2, 'nb' => 8],
        ];

        $this->conn->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn($rows);

        $result = $this->service->getDelaiMoyenTraitement('2026-01-01', '2026-01-31', [1]);

        $this->assertCount(2, $result);
        $this->assertEquals(24.5, $result[0]['delai_moyen_h']);
        $this->assertEquals('Bénin', $result[0]['pays']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 13. getEvolutionTemporelle
    // ─────────────────────────────────────────────────────────────────────────

    public function testGetEvolutionTemporelleDaily(): void
    {
        $rows = [
            ['periode' => '2026-01-01', 'soumises' => '5', 'transmises' => '3'],
            ['periode' => '2026-01-02', 'soumises' => '7', 'transmises' => '4'],
        ];

        $this->conn->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn($rows);

        $result = $this->service->getEvolutionTemporelle('2026-01-01', '2026-01-31', [1], 'day');

        $this->assertEquals(['2026-01-01', '2026-01-02'], $result['labels']);
        $this->assertEquals([5, 7], $result['soumises']);
        $this->assertEquals([3, 4], $result['transmises']);
    }

    public function testGetEvolutionTemporelleWeekly(): void
    {
        $rows = [
            ['periode' => '2026-01', 'soumises' => '12', 'transmises' => '7'],
            ['periode' => '2026-02', 'soumises' => '15', 'transmises' => '9'],
        ];

        $this->conn->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn($rows);

        $result = $this->service->getEvolutionTemporelle('2026-01-01', '2026-01-31', [1], 'week');

        $this->assertEquals(['2026-01', '2026-02'], $result['labels']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 14. invalidateAll
    // ─────────────────────────────────────────────────────────────────────────

    public function testInvalidateAllAucuneException(): void
    {
        // Ce test vérifie que invalidateAll ne lance pas d'exception
        $this->service->invalidateAll();
        $this->assertTrue(true);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helper
    // ─────────────────────────────────────────────────────────────────────────

    private function setupCacheHit(array $result): void
    {
        $this->cache->expects($this->once())
            ->method('get')
            ->will($this->returnCallback(function ($key, $callback) use ($result) {
                $item = $this->createMock(ItemInterface::class);
                $item->expects($this->once())->method('expiresAfter');
                return $result;
            }));
    }
}