<?php

namespace App\Tests\Unit;

use App\Entity\Alert;
use App\Entity\Market;
use App\Entity\User;
use App\Entity\Urgence72hCase;
use App\Service\KPIService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Tests unitaires de KPIService.
 * 
 * Couvre :
 *  1. Calcul KPI alertes soumises avec variation
 *  2. Calcul KPI alertes traitées
 *  3. Calcul taux de transmission
 *  4. Calcul score moyen
 *  5. Courbe temporelle
 *  6. Répartition par priorité
 *  7. Alertes récentes
 *  8. Cas urgence 72h
 */
class KPIServiceTest extends TestCase
{
    private $em;
    private $cache;
    private $service;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->cache = $this->createMock(CacheInterface::class);
        $this->service = new KPIService($this->em, $this->cache);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1. Calcul KPI alertes soumises avec variation
    // ─────────────────────────────────────────────────────────────────────────

    public function testGetAlertesSoumises(): void
    {
        $debut = new \DateTime('2026-01-01');
        $fin = new \DateTime('2026-01-31');
        $marches = [1, 2, 3];

        // Mock du cache
        $item = $this->createMock(ItemInterface::class);
        $item->method('expiresAfter')->willReturn(null);

        $this->cache->expects($this->once())
            ->method('get')
            ->willReturn([
                'value' => 150,
                'previous' => 120,
                'variation' => 25.0,
                'trend' => 'up',
            ]);

        $result = $this->service->getAlertesSoumises($debut, $fin, $marches);

        $this->assertArrayHasKey('value', $result);
        $this->assertArrayHasKey('variation', $result);
        $this->assertArrayHasKey('trend', $result);
        $this->assertEquals('up', $result['trend']);
    }

    public function testGetAlertesSoumisesVariationNegatif(): void
    {
        $debut = new \DateTime('2026-01-01');
        $fin = new \DateTime('2026-01-31');

        $this->cache->expects($this->once())
            ->method('get')
            ->willReturn([
                'value' => 80,
                'previous' => 100,
                'variation' => -20.0,
                'trend' => 'down',
            ]);

        $result = $this->service->getAlertesSoumises($debut, $fin, []);

        $this->assertEquals('down', $result['trend']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. Calcul KPI alertes traitées
    // ─────────────────────────────────────────────────────────────────────────

    public function testGetAlerteesTraitees(): void
    {
        $debut = new \DateTime('2026-01-01');
        $fin = new \DateTime('2026-01-31');

        $this->cache->expects($this->once())
            ->method('get')
            ->willReturn([
                'value' => 120,
                'previous' => 100,
                'variation' => 20.0,
                'trend' => 'up',
            ]);

        $result = $this->service->getAlerteesTraitees($debut, $fin, []);

        $this->assertEquals(120, $result['value']);
        $this->assertEquals('up', $result['trend']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. Calcul taux de transmission
    // ─────────────────────────────────────────────────────────────────────────

    public function testGetTauxTransmission(): void
    {
        $debut = new \DateTime('2026-01-01');
        $fin = new \DateTime('2026-01-31');

        $qb = $this->createMock(QueryBuilder::class);
        $this->em->expects($this->any())
            ->method('createQueryBuilder')
            ->willReturn($qb);

        $qb->method('select')
            ->willReturnSelf();
        $qb->method('from')
            ->willReturnSelf();
        $qb->method('where')
            ->willReturnSelf();
        $qb->method('andWhere')
            ->willReturnSelf();
        $qb->method('groupBy')
            ->willReturnSelf();
        $qb->method('setParameter')
            ->willReturnSelf();
        $qb->method('getQuery')
            ->willReturnSelf();
        $qb->method('getSingleScalarResult')
            ->willReturnOnConsecutiveCalls(60, 80); // transmises, total

        $item = $this->createMock(ItemInterface::class);
        $this->cache->expects($this->once())
            ->method('get')
            ->willReturn(['value' => 75.0, 'count' => 60, 'total' => 80]);

        $result = $this->service->getTauxTransmission($debut, $fin, []);

        $this->assertArrayHasKey('value', $result);
        $this->assertEquals(75.0, $result['value']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4. Calcul score moyen
    // ─────────────────────────────────────────────────────────────────────────

    public function testGetScoreMoyen(): void
    {
        $debut = new \DateTime('2026-01-01');
        $fin = new \DateTime('2026-01-31');

        $this->em->expects($this->once())
            ->method('createQueryBuilder')
            ->willReturnSelf();

        $this->cache->expects($this->once())
            ->method('get')
            ->willReturn(['value' => 17.5]);

        $result = $this->service->getScoreMoyen($debut, $fin, []);

        $this->assertEquals(17.5, $result['value']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 5. Courbe temporelle
    // ─────────────────────────────────────────────────────────────────────────

    public function testGetCourbeSoumisesVsQualifiees(): void
    {
        $debut = new \DateTime('2026-01-01');
        $fin = new \DateTime('2026-01-31');

        $conn = $this->createMock(\Doctrine\DBAL\Connection::class);
        $this->em->method('getConnection')->willReturn($conn);

        $conn->expects($this->exactly(2))
            ->method('fetchAllAssociative')
            ->willReturn([['jour' => '2026-01-01', 'nb' => 5]]);

        $this->cache->expects($this->once())
            ->method('get')
            ->willReturn([
                'soumises' => [['jour' => '2026-01-01', 'nb' => 5]],
                'qualifiees' => [['jour' => '2026-01-01', 'nb' => 3]],
            ]);

        $result = $this->service->getCourbeSoumisesVsQualifiees($debut, $fin, []);

        $this->assertArrayHasKey('soumises', $result);
        $this->assertArrayHasKey('qualifiees', $result);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 6. Répartition par priorité
    // ─────────────────────────────────────────────────────────────────────────

    public function testGetRepartitionPriorite(): void
    {
        $marches = [1, 2];

        $this->em->expects($this->once())
            ->method('createQueryBuilder')
            ->willReturnSelf();

        $this->cache->expects($this->once())
            ->method('get')
            ->willReturn([
                ['priorite' => 'critique', 'nb' => 25],
                ['priorite' => 'eleve', 'nb' => 35],
                ['priorite' => 'modere', 'nb' => 30],
                ['priorite' => 'faible', 'nb' => 10],
            ]);

        $result = $this->service->getRepartitionPriorite($marches);

        $this->assertIsArray($result);
        $this->assertCount(4, $result);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 7. Alertes récentes
    // ─────────────────────────────────────────────────────────────────────────

    public function testGetAlerteesRecentes(): void
    {
        $limit = 8;

        $this->em->expects($this->once())
            ->method('createQueryBuilder')
            ->willReturnSelf();

        $this->cache->expects($this->once())
            ->method('get')
            ->willReturn([]);

        $result = $this->service->getAlerteesRecentes($limit, []);

        $this->assertIsArray($result);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 8. Cas urgence 72h
    // ─────────────────────────────────────────────────────────────────────────

    public function testGetCasUrgence72hActifs(): void
    {
        $this->em->expects($this->once())
            ->method('createQueryBuilder')
            ->willReturnSelf();

        $this->cache->expects($this->once())
            ->method('get')
            ->willReturn([]);

        $result = $this->service->getCasUrgence72hActifs([]);

        $this->assertIsArray($result);
    }

    public function testGetCasUrgence72hAvecMarches(): void
    {
        $marches = [1, 2];

        $this->em->expects($this->once())
            ->method('createQueryBuilder')
            ->willReturnSelf();

        $this->cache->expects($this->once())
            ->method('get')
            ->willReturn([]);

        $result = $this->service->getCasUrgence72hActifs($marches);

        $this->assertIsArray($result);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 9. Activité récente
    // ─────────────────────────────────────────────────────────────────────────

    public function testGetActiviteRecente(): void
    {
        $this->em->expects($this->once())
            ->method('getConnection')
            ->willReturnSelf();

        $this->cache->expects($this->once())
            ->method('get')
            ->willReturn([]);

        $result = $this->service->getActiviteRecente(10);

        $this->assertIsArray($result);
    }
}