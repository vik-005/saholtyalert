<?php

namespace App\Tests\Unit;

use App\Dto\AuditFilterDTO;
use App\Entity\Market;
use App\Entity\User;
use App\Enum\UserRoleEnum;
use App\Repository\AlertAuditRepository;
use App\Service\AuditService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Tests unitaires des filtres avances du module Audit GEI.
 *
 * Couvre :
 *  1. Aucun filtre applique (etat par defaut complet)
 *  2. Un seul filtre a la fois pour chaque dimension disponible
 *  3. Filtres combines - 3 scenarios realistes
 *  4. Filtre reflete dans l URL et changement de mode sans perte de filtre
 *  5. Unicite et stabilite des cles de cache
 *  6. Scope utilisateur par role (Agent, PFT, Superadmin)
 *  7. Coherence mathematique stricte entre tous les modes d affichage
 *  8. Etat vide (0 resultat) - comportement propre sans exception
 *  9. Limites de pagination (clamp min/max)
 * 10. Precision des bornes de date (00:00:00 / 23:59:59)
 * 11. Compatibilite retro du parametre "pays" vers "markets"
 */
class AuditFilterTest extends TestCase
{
    // Helpers

    private function createMockUser(UserRoleEnum $role, int $id = 1, array $managedMarkets = []): User
    {
        $user = new User();
        // PHP 8.1+ : ReflectionProperty::setValue() fonctionne sans setAccessible()
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);
        $user->setEmail("user{$id}@gei.example.com");
        $user->setPrenom('Test');
        $user->setNom("User{$id}");
        $user->setRole($role);
        foreach ($managedMarkets as $market) {
            $user->addMarket($market);
        }
        return $user;
    }

    private function createMockMarket(int $id, string $nom, string $iso = 'BEN'): Market
    {
        $market = new Market();
        (new \ReflectionProperty(Market::class, 'id'))->setValue($market, $id);
        $market->setNom($nom);
        $market->setCodeIso3($iso);
        $market->setActif(true);
        return $market;
    }

    // 1. Aucun filtre applique

    public function testFromRequestNoFilters(): void
    {
        $dto = AuditFilterDTO::fromRequest(new Request());
        $this->assertFalse($dto->hasActiveFilters());
        $this->assertEmpty($dto->markets);
        $this->assertEmpty($dto->corridors);
        $this->assertEmpty($dto->categories);
        $this->assertEmpty($dto->statuts);
        $this->assertEmpty($dto->urgences);
        $this->assertEmpty($dto->niveauxPriorite);
        $this->assertNull($dto->dateFrom);
        $this->assertNull($dto->dateTo);
        $this->assertNull($dto->scoreMin);
        $this->assertNull($dto->scoreMax);
        $this->assertNull($dto->agentId);
        $this->assertNull($dto->managerId);
        $this->assertNull($dto->origine);
        $this->assertSame('', $dto->operateur);
        $this->assertSame('', $dto->marque);
        $this->assertSame('', $dto->texteLibre);
        $this->assertSame('texte', $dto->vue);
        $this->assertSame(1, $dto->page);
        $this->assertSame(50, $dto->limit);
    }

    // 2. Un seul filtre a la fois

    public function testSingleFilterMarkets(): void
    {
        $dto = AuditFilterDTO::fromRequest(new Request(['markets' => [1, 2]]));
        $this->assertTrue($dto->hasActiveFilters());
        $this->assertSame([1, 2], $dto->markets);
        $this->assertEmpty($dto->statuts);
        $this->assertEmpty($dto->urgences);
    }

    public function testSingleFilterMarketsLegacyPays(): void
    {
        $dto = AuditFilterDTO::fromRequest(new Request(['pays' => [3, 4]]));
        $this->assertTrue($dto->hasActiveFilters());
        $this->assertSame([3, 4], $dto->markets);
    }

    public function testSingleFilterStatut(): void
    {
        $dto = AuditFilterDTO::fromRequest(new Request(['statuts' => ['nouveau', 'qualifie']]));
        $this->assertTrue($dto->hasActiveFilters());
        $this->assertSame(['nouveau', 'qualifie'], $dto->statuts);
        $this->assertEmpty($dto->markets);
    }

    public function testSingleFilterUrgence(): void
    {
        $dto = AuditFilterDTO::fromRequest(new Request(['urgences' => ['critique', 'elevee']]));
        $this->assertTrue($dto->hasActiveFilters());
        $this->assertSame(['critique', 'elevee'], $dto->urgences);
    }

    public function testSingleFilterNiveauPriorite(): void
    {
        $dto = AuditFilterDTO::fromRequest(new Request(['niveauxPriorite' => ['critique']]));
        $this->assertTrue($dto->hasActiveFilters());
        $this->assertSame(['critique'], $dto->niveauxPriorite);
    }

    public function testSingleFilterScoreRange(): void
    {
        $dto = AuditFilterDTO::fromRequest(new Request(['scoreMin' => 15, 'scoreMax' => 25]));
        $this->assertTrue($dto->hasActiveFilters());
        $this->assertSame(15, $dto->scoreMin);
        $this->assertSame(25, $dto->scoreMax);
    }

    public function testSingleFilterDates(): void
    {
        $dto = AuditFilterDTO::fromRequest(new Request(['dateFrom' => '2026-01-01', 'dateTo' => '2026-01-31']));
        $this->assertTrue($dto->hasActiveFilters());
        $this->assertSame('2026-01-01 00:00:00', $dto->dateFrom->format('Y-m-d H:i:s'));
        $this->assertSame('2026-02-01 00:00:00', $dto->dateTo->format('Y-m-d H:i:s'));
    }

    public function testSingleFilterAgentId(): void
    {
        $dto = AuditFilterDTO::fromRequest(new Request(['agentId' => '42']));
        $this->assertTrue($dto->hasActiveFilters());
        $this->assertSame(42, $dto->agentId);
        $this->assertNull($dto->managerId);
    }

    public function testSingleFilterManagerId(): void
    {
        $dto = AuditFilterDTO::fromRequest(new Request(['managerId' => '7']));
        $this->assertTrue($dto->hasActiveFilters());
        $this->assertSame(7, $dto->managerId);
        $this->assertNull($dto->agentId);
    }

    public function testSingleFilterOrigine(): void
    {
        $dto = AuditFilterDTO::fromRequest(new Request(['origine' => 'terrain']));
        $this->assertTrue($dto->hasActiveFilters());
        $this->assertSame('terrain', $dto->origine);
    }

    public function testSingleFilterOperateur(): void
    {
        $dto = AuditFilterDTO::fromRequest(new Request(['operateur' => 'TotalEnergies']));
        $this->assertTrue($dto->hasActiveFilters());
        $this->assertSame('TotalEnergies', $dto->operateur);
    }

    public function testSingleFilterMarque(): void
    {
        $dto = AuditFilterDTO::fromRequest(new Request(['marque' => 'Marlboro']));
        $this->assertTrue($dto->hasActiveFilters());
        $this->assertSame('Marlboro', $dto->marque);
    }

    public function testSingleFilterTexteLibre(): void
    {
        $dto = AuditFilterDTO::fromRequest(new Request(['texteLibre' => 'Contrebande Cotonou']));
        $this->assertTrue($dto->hasActiveFilters());
        $this->assertSame('Contrebande Cotonou', $dto->texteLibre);
    }

    public function testSingleFilterCategories(): void
    {
        $dto = AuditFilterDTO::fromRequest(new Request(['categories' => ['fraude_fiscale', 'contrebande']]));
        $this->assertTrue($dto->hasActiveFilters());
        $this->assertSame(['fraude_fiscale', 'contrebande'], $dto->categories);
    }

    public function testSingleFilterCorridors(): void
    {
        $dto = AuditFilterDTO::fromRequest(new Request(['corridors' => ['Cotonou-Niamey']]));
        $this->assertTrue($dto->hasActiveFilters());
        $this->assertSame(['Cotonou-Niamey'], $dto->corridors);
    }

    // 3. Filtres combines - 3 scenarios realistes

    public function testCombinedFiltersScenario1MarketUrgenceDate(): void
    {
        $dto = AuditFilterDTO::fromRequest(new Request([
            'markets'  => [1],
            'urgences' => ['critique'],
            'dateFrom' => '2026-02-01',
            'dateTo'   => '2026-02-28',
            'vue'      => 'tableau',
        ]));
        $this->assertTrue($dto->hasActiveFilters());
        $this->assertSame([1], $dto->markets);
        $this->assertSame(['critique'], $dto->urgences);
        $this->assertSame('2026-02-01', $dto->dateFrom->format('Y-m-d'));
        $this->assertSame('2026-03-01', $dto->dateTo->format('Y-m-d'));
        $this->assertSame('tableau', $dto->vue);
        $this->assertEmpty($dto->statuts);
        $this->assertEmpty($dto->niveauxPriorite);
    }

    public function testCombinedFiltersScenario2StatutScoreMin(): void
    {
        $dto = AuditFilterDTO::fromRequest(new Request([
            'statuts'  => ['qualifie', 'transmis'],
            'scoreMin' => 18,
            'vue'      => 'graphique',
        ]));
        $this->assertTrue($dto->hasActiveFilters());
        $this->assertSame(['qualifie', 'transmis'], $dto->statuts);
        $this->assertSame(18, $dto->scoreMin);
        $this->assertSame('graphique', $dto->vue);
        $this->assertNull($dto->scoreMax);
        $this->assertEmpty($dto->markets);
    }

    public function testCombinedFiltersScenario3AgentOrigineCategorie(): void
    {
        $dto = AuditFilterDTO::fromRequest(new Request([
            'agentId'    => 10,
            'origine'    => 'partenaire',
            'categories' => ['fraude_fiscale', 'contrebande'],
            'vue'        => 'carte',
        ]));
        $this->assertTrue($dto->hasActiveFilters());
        $this->assertSame(10, $dto->agentId);
        $this->assertSame('partenaire', $dto->origine);
        $this->assertSame(['fraude_fiscale', 'contrebande'], $dto->categories);
        $this->assertSame('carte', $dto->vue);
        $this->assertEmpty($dto->markets);
        $this->assertEmpty($dto->urgences);
    }

    // 4. Filtre reflete dans l URL et changement de mode sans perte

    public function testFilterReflectedInUrlAndModeSwitch(): void
    {
        $dto = AuditFilterDTO::fromRequest(new Request([
            'markets'  => [1, 2],
            'statuts'  => ['qualifie'],
            'scoreMin' => 12,
            'vue'      => 'tableau',
        ]));
        $params = $dto->toQueryParams();
        $this->assertSame([1, 2], $params['markets']);
        $this->assertSame(['qualifie'], $params['statuts']);
        $this->assertSame(12, $params['scoreMin']);
        $this->assertSame('tableau', $params['vue']);

        $dto->vue = 'graphique';
        $p2 = $dto->toQueryParams();
        $this->assertSame('graphique', $p2['vue']);
        $this->assertSame([1, 2], $p2['markets']);
        $this->assertSame(12, $p2['scoreMin']);

        $dto->vue = 'carte';
        $p3 = $dto->toQueryParams();
        $this->assertSame('carte', $p3['vue']);
        $this->assertSame([1, 2], $p3['markets']);

        $dto->vue = 'texte';
        $p4 = $dto->toQueryParams();
        $this->assertSame('texte', $p4['vue']);
        $this->assertSame(['qualifie'], $p4['statuts']);
    }

    // 5. Unicite et stabilite des cles de cache

    public function testCacheKeyUniquenessAndStability(): void
    {
        $dto1 = new AuditFilterDTO();
        $dto1->markets = [1];
        $dto1->statuts = ['qualifie'];

        $dto2 = new AuditFilterDTO();
        $dto2->markets = [1];
        $dto2->statuts = ['rejete'];

        $this->assertNotSame($dto1->cacheKey('count'), $dto2->cacheKey('count'));
        $this->assertSame($dto1->cacheKey('count'), $dto1->cacheKey('count'));
        $this->assertNotSame($dto1->cacheKey('count'), $dto1->cacheKey('stats'));
        $this->assertStringStartsWith('audit_', $dto1->cacheKey('count'));
    }

    // 6. Scope utilisateur par role

    public function testApplyUserScope(): void
    {
        $auditRepo = $this->createMock(AlertAuditRepository::class);
        $em        = $this->createMock(EntityManagerInterface::class);
        $cache     = $this->createMock(CacheInterface::class);
        $service   = new AuditService($auditRepo, $em, $cache);

        $agent = $this->createMockUser(UserRoleEnum::EMETTEUR_TERRAIN, 99);
        $scopedAgent = $service->applyUserScope(new AuditFilterDTO(), $agent);
        $this->assertSame(99, $scopedAgent->agentId);

        $m1      = $this->createMockMarket(10, 'Benin', 'BEN');
        $m2      = $this->createMockMarket(20, 'Togo', 'TGO');
        $manager = $this->createMockUser(UserRoleEnum::PFT, 5, [$m1, $m2]);

        $dtoManager          = new AuditFilterDTO();
        $dtoManager->markets = [10, 20, 30];
        $scopedManager       = $service->applyUserScope($dtoManager, $manager);
        $this->assertEqualsCanonicalizing([10, 20], $scopedManager->markets);
        $this->assertNotContains(30, $scopedManager->markets);

        $dtoManagerNoFilter = new AuditFilterDTO();
        $scopedNoFilter     = $service->applyUserScope($dtoManagerNoFilter, $manager);
        $this->assertEmpty($scopedNoFilter->markets);

        $superadmin          = $this->createMockUser(UserRoleEnum::SUPERADMIN, 1);
        $dtoAdmin            = new AuditFilterDTO();
        $dtoAdmin->markets   = [10, 20, 30, 99];
        $scopedAdmin         = $service->applyUserScope($dtoAdmin, $superadmin);
        $this->assertSame([10, 20, 30, 99], $scopedAdmin->markets);
    }

    // 7. Coherence mathematique stricte inter-modes

    public function testCrossViewDataConsistency(): void
    {
        $auditRepo = $this->createMock(AlertAuditRepository::class);
        $em        = $this->createMock(EntityManagerInterface::class);
        $cache     = $this->createMock(CacheInterface::class);

        $mockStats = [
            'total'             => 100,
            'scoreMoyen'        => 14.5,
            'critiques'         => 25,
            'tauxTransmission'  => 60.0,
            'parMarche'         => [
                ['nom' => 'Benin', 'code_iso3' => 'BEN', 'nb' => 60, 'score_moyen' => 15.0],
                ['nom' => 'Togo',  'code_iso3' => 'TGO', 'nb' => 40, 'score_moyen' => 13.8],
            ],
            'parStatut' => [
                ['statut' => 'qualifie', 'nb' => 50],
                ['statut' => 'nouveau',  'nb' => 30],
                ['statut' => 'transmis', 'nb' => 20],
            ],
            'parNiveauPriorite' => [
                ['niveauPriorite' => 'critique', 'nb' => 25],
                ['niveauPriorite' => 'eleve',    'nb' => 35],
                ['niveauPriorite' => 'modere',   'nb' => 30],
                ['niveauPriorite' => 'faible',   'nb' => 10],
            ],
            'parCategorie' => [
                ['categorie' => 'fraude', 'nb' => 70],
                ['categorie' => 'douane', 'nb' => 30],
            ],
            'parMois' => [
                ['mois' => '2026-01', 'nb' => 40],
                ['mois' => '2026-02', 'nb' => 60],
            ],
            'parAgent' => [
                ['agent' => 'Jean Dupont',  'nb' => 60],
                ['agent' => 'Alice Martin', 'nb' => 40],
            ],
            'parUrgence' => [
                ['urgence' => 'critique', 'nb' => 30],
                ['urgence' => 'elevee',   'nb' => 70],
            ],
            'parExploitabilite' => [
                ['exploitabilite' => 'forte',   'nb' => 80],
                ['exploitabilite' => 'moyenne', 'nb' => 20],
            ],
            'donneesCartographie' => [
                ['market_id' => 1, 'nom' => 'Benin', 'code_iso3' => 'BEN', 'latitude' => '9.3077', 'longitude' => '2.3158', 'nb' => 60, 'max_priorite' => 'critique'],
                ['market_id' => 2, 'nom' => 'Togo',  'code_iso3' => 'TGO', 'latitude' => '8.6195', 'longitude' => '0.8248', 'nb' => 40, 'max_priorite' => 'eleve'],
            ],
        ];

        $auditRepo->method('aggregateStats')->willReturn($mockStats);
        $auditRepo->method('countByFilters')->willReturn(100);
        $auditRepo->method('findPaginated')->willReturn(array_fill(0, 50, ['id' => 1]));

        $service   = new AuditService($auditRepo, $em, $cache);
        $admin     = $this->createMockUser(UserRoleEnum::SUPERADMIN, 1);
        $dto       = new AuditFilterDTO();
        $chartData = $service->getChartData($dto, $admin);
        $results   = $service->getResults($dto, $admin);

        $this->assertSame(100, $results['total']);
        $this->assertSame(100, $chartData['total']);
        $this->assertSame(100, array_sum($chartData['parPays']));
        $this->assertSame(100, array_sum($chartData['parStatut']));
        $this->assertSame(100, array_sum($chartData['parPriorite']));
        $this->assertArrayHasKey('Critique', $chartData['parPriorite']);
        $this->assertArrayHasKey('Faible', $chartData['parPriorite']);
        $this->assertSame(25, $chartData['parPriorite']['Critique']);
        $this->assertSame(10, $chartData['parPriorite']['Faible']);
        $this->assertSame(100, array_sum($chartData['parUrgence']));
        $this->assertSame(100, array_sum($chartData['parCategorie']));
        $this->assertSame(100, array_sum($chartData['parAgent']));
        $this->assertSame(100, array_sum($chartData['parAnnee']));
        $carteSum = array_sum(array_column($chartData['donneesCartographie'], 'nb'));
        $this->assertSame(100, $carteSum, 'TEST CRITIQUE : Somme carte == Total');
    }

    // 8. Etat vide (0 resultat)

    public function testZeroResultsState(): void
    {
        $auditRepo = $this->createMock(AlertAuditRepository::class);
        $em        = $this->createMock(EntityManagerInterface::class);
        $cache     = $this->createMock(CacheInterface::class);

        $empty = [
            'total' => 0, 'scoreMoyen' => null, 'critiques' => 0, 'tauxTransmission' => null,
            'parMarche' => [], 'parStatut' => [], 'parNiveauPriorite' => [], 'parCategorie' => [],
            'parMois' => [], 'parAgent' => [], 'parUrgence' => [], 'parExploitabilite' => [],
            'donneesCartographie' => [],
        ];

        $auditRepo->method('aggregateStats')->willReturn($empty);
        $auditRepo->method('countByFilters')->willReturn(0);
        $auditRepo->method('findPaginated')->willReturn([]);

        $service   = new AuditService($auditRepo, $em, $cache);
        $admin     = $this->createMockUser(UserRoleEnum::SUPERADMIN, 1);
        $dto       = new AuditFilterDTO();
        $dto->texteLibre = 'INEXISTANT_XYZ_999';

        $results   = $service->getResults($dto, $admin);
        $chartData = $service->getChartData($dto, $admin);

        $this->assertSame(0, $results['total']);
        $this->assertSame(0, $chartData['total']);
        $this->assertEmpty($results['rows']);
        $this->assertEmpty($chartData['parPays']);
        $this->assertEmpty($chartData['parStatut']);
        $this->assertEmpty($chartData['parPriorite']);
        $this->assertEmpty($chartData['donneesCartographie']);
        $this->assertSame(0, $results['pages']);
    }

    // 9. Limites de pagination

    public function testPaginationClamping(): void
    {
        // Limit trop haute : plafonnee a 100
        $dto = AuditFilterDTO::fromRequest(new Request(['limit' => 9999, 'page' => 0]));
        $this->assertSame(100, $dto->limit, 'limit=9999 doit etre plafonnee a 100');
        $this->assertSame(1, $dto->page, 'page=0 doit etre ramenee a 1 (minimum).');

        // limit=0 est evalue "empty" par PHP, donc la valeur par defaut (50) est conservee
        $dto2 = AuditFilterDTO::fromRequest(new Request(['limit' => 0]));
        $this->assertSame(50, $dto2->limit, 'limit=0 est falsy pour !empty(), la valeur par defaut 50 est conservee');

        // Valeurs normales non alterees
        $dto3 = AuditFilterDTO::fromRequest(new Request(['limit' => 25, 'page' => 3]));
        $this->assertSame(25, $dto3->limit);
        $this->assertSame(3, $dto3->page);
    }

    // 10. Precision des bornes de date

    public function testDateBoundaryPrecision(): void
    {
        $dto = AuditFilterDTO::fromRequest(new Request(['dateFrom' => '2026-06-01', 'dateTo' => '2026-06-30']));
        $this->assertSame('2026-06-01 00:00:00', $dto->dateFrom->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-01 00:00:00', $dto->dateTo->format('Y-m-d H:i:s'));
    }

    public function testInvertedScoreBoundsAreNormalised(): void
    {
        $dto = AuditFilterDTO::fromRequest(new Request(['scoreMin' => '24', 'scoreMax' => '10']));

        $this->assertSame(10, $dto->scoreMin);
        $this->assertSame(24, $dto->scoreMax);
    }

    public function testInvalidDatesDoNotBreakTheFilter(): void
    {
        $dto = AuditFilterDTO::fromRequest(new Request(['dateFrom' => 'not-a-date']));

        $this->assertNull($dto->dateFrom);
        $this->assertNull($dto->dateTo);
    }
}
