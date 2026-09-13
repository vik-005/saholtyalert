<?php

namespace App\Tests\Unit;

use App\Dto\AlertFilterDTO;
use App\Entity\Alert;
use App\Entity\Market;
use App\Entity\User;
use App\Enum\UserRoleEnum;
use App\Repository\AlertRepository;
use App\Service\AlertFilterService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires de AlertFilterService.
 * 
 * Couvre :
 *  1. Construction QueryBuilder sans filtres
 *  2. Application des filtres un par un
 *  3. Filtres combinés
 *  4. Gestion autorisations rôle (EMETTEUR_TERRAIN, PFT, SUPERADMIN)
 *  5. Tri et pagination
 */
class AlertFilterServiceTest extends TestCase
{
    private $em;
    private $service;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->service = new AlertFilterService($this->em);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1. Construction QueryBuilder sans filtres
    // ─────────────────────────────────────────────────────────────────────────

    public function testBuildQueryBuilderSansFiltres(): void
    {
        $dto = new AlertFilterDTO();
        $user = $this->createMockUser(UserRoleEnum::SUPERADMIN);

        $qb = $this->service->buildQueryBuilder($dto, $user);

        $this->assertInstanceOf(QueryBuilder::class, $qb);
        $query = $qb->getQuery();
        $dql = $query->getDQL();

        $this->assertStringContainsString('FROM App\Entity\Alert a', $dql);
        $this->assertStringContainsString('LEFT JOIN a.market m', $dql);
        $this->assertStringContainsString('LEFT JOIN a.emetteur e', $dql);
    }

    public function testBuildQueryBuilderAvecDistinct(): void
    {
        $dto = new AlertFilterDTO();
        $user = $this->createMockUser(UserRoleEnum::SUPERADMIN);

        $qb = $this->service->buildQueryBuilder($dto, $user, 'a', true);

        $query = $qb->getQuery();
        $dql = $query->getDQL();

        $this->assertStringContainsString('SELECT DISTINCT', $dql);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. Application des filtres un par un
    // ─────────────────────────────────────────────────────────────────────────

    public function testFiltreStatut(): void
    {
        $dto = new AlertFilterDTO();
        $dto->setStatut('validee');

        $qb = $this->service->buildQueryBuilder($dto, $this->createMockUser(UserRoleEnum::SUPERADMIN));
        $dql = $qb->getQuery()->getDQL();

        $this->assertStringContainsString('a.statut = :statut', $dql);
        $this->assertEquals('validee', $qb->getParameter('statut')->getValue());
    }

    public function testFiltreNiveauPriorite(): void
    {
        $dto = new AlertFilterDTO();
        $dto->setNiveauPriorite('critique');

        $qb = $this->service->buildQueryBuilder($dto, $this->createMockUser(UserRoleEnum::SUPERADMIN));
        $dql = $qb->getQuery()->getDQL();

        $this->assertStringContainsString('a.niveauPriorite = :priorite', $dql);
    }

    public function testFiltreMarket(): void
    {
        $market = new Market();
        $market->setId(5);

        $dto = new AlertFilterDTO();
        $dto->setMarket(5);

        $qb = $this->service->buildQueryBuilder($dto, $this->createMockUser(UserRoleEnum::SUPERADMIN));
        $dql = $qb->getQuery()->getDQL();

        $this->assertStringContainsString('a.market = :market', $dql);
    }

    public function testFiltreSearch(): void
    {
        $dto = new AlertFilterDTO();
        $dto->setSearch('controle');

        $qb = $this->service->buildQueryBuilder($dto, $this->createMockUser(UserRoleEnum::SUPERADMIN));
        $dql = $qb->getQuery()->getDQL();

        $this->assertStringContainsString('LIKE :search', $dql);
        $this->assertStringContainsString('%controle%', $qb->getParameter('search')->getValue());
    }

    public function testFiltreDateDebut(): void
    {
        $dto = new AlertFilterDTO();
        $dto->setDateDebut('2026-01-15');

        $qb = $this->service->buildQueryBuilder($dto, $this->createMockUser(UserRoleEnum::SUPERADMIN));
        $dql = $qb->getQuery()->getDQL();

        $this->assertStringContainsString('a.dateCreation >= :dateDebut', $dql);
    }

    public function testFiltreDateFin(): void
    {
        $dto = new AlertFilterDTO();
        $dto->setDateFin('2026-01-31');

        $qb = $this->service->buildQueryBuilder($dto, $this->createMockUser(UserRoleEnum::SUPERADMIN));
        $dql = $qb->getQuery()->getDQL();

        $this->assertStringContainsString('a.dateCreation <= :dateFin', $dql);
    }

    public function testFiltreUrgence72h(): void
    {
        $dto = new AlertFilterDTO();
        $dto->setUrgence72h(true);

        $qb = $this->service->buildQueryBuilder($dto, $this->createMockUser(UserRoleEnum::SUPERADMIN));
        $dql = $qb->getQuery()->getDQL();

        $this->assertStringContainsString('a.urgence = :urg72h', $dql);
    }

    public function testFiltreScoreMin(): void
    {
        $dto = new AlertFilterDTO();
        $dto->setScoreMin(15);

        $qb = $this->service->buildQueryBuilder($dto, $this->createMockUser(UserRoleEnum::SUPERADMIN));
        $dql = $qb->getQuery()->getDQL();

        $this->assertStringContainsString('>= :scoreMin', $dql);
    }

    public function testFiltreScoreMax(): void
    {
        $dto = new AlertFilterDTO();
        $dto->setScoreMax(20);

        $qb = $this->service->buildQueryBuilder($dto, $this->createMockUser(UserRoleEnum::SUPERADMIN));
        $dql = $qb->getQuery()->getDQL();

        $this->assertStringContainsString('<= :scoreMax', $dql);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. Filtres combinés
    // ─────────────────────────────────────────────────────────────────────────

    public function testFiltresCombines(): void
    {
        $dto = new AlertFilterDTO();
        $dto->setStatut('validee');
        $dto->setNiveauPriorite('critique');
        $dto->setDateDebut('2026-01-01');
        $dto->setDateFin('2026-01-31');
        $dto->setMarket(5);

        $qb = $this->service->buildQueryBuilder($dto, $this->createMockUser(UserRoleEnum::SUPERADMIN));
        $dql = $qb->getQuery()->getDQL();

        $this->assertStringContainsString('a.statut = :statut', $dql);
        $this->assertStringContainsString('a.niveauPriorite = :priorite', $dql);
        $this->assertStringContainsString('a.dateCreation >= :dateDebut', $dql);
        $this->assertStringContainsString('a.dateCreation <= :dateFin', $dql);
    }

    // ─────────────────────────────────────────���───────────────────────────────
    // 4. Gestion autorisations rôle
    // ─────────────────────────────────────────────────────────────────────────

    public function testRoleEmetteurTerrain(): void
    {
        $user = $this->createMockUser(UserRoleEnum::EMETTEUR_TERRAIN);
        $user->setId(42);

        $dto = new AlertFilterDTO();

        $qb = $this->service->buildQueryBuilder($dto, $user);
        $dql = $qb->getQuery()->getDQL();

        $this->assertStringContainsString('a.emetteur = :currentUser', $dql);
        $this->assertEquals(42, $qb->getParameter('currentUser')->getValue());
    }

    public function testRolePFT(): void
    {
        $market1 = new Market();
        $market1->setId(1);
        $market2 = new Market();
        $market2->setId(2);

        $user = $this->createMockUser(UserRoleEnum::PFT);
        $user->addMarket($market1);
        $user->addMarket($market2);

        $dto = new AlertFilterDTO();

        $qb = $this->service->buildQueryBuilder($dto, $user);
        $dql = $qb->getQuery()->getDQL();

        $this->assertStringContainsString('a.market IN (:managedMarkets)', $dql);
    }

    public function testRolePFTAucunMarket(): void
    {
        $user = $this->createMockUser(UserRoleEnum::PFT);

        $dto = new AlertFilterDTO();

        $qb = $this->service->buildQueryBuilder($dto, $user);
        $dql = $qb->getQuery()->getDQL();

        // Aucun marché géré → aucune alerte visible
        $this->assertStringContainsString('1 = 0', $dql);
    }

    public function testRoleSuperAdmin(): void
    {
        $user = $this->createMockUser(UserRoleEnum::SUPERADMIN);
        $dto = new AlertFilterDTO();

        $qb = $this->service->buildQueryBuilder($dto, $user);
        $dql = $qb->getQuery()->getDQL();

        // Pas de restriction
        $this->assertStringNotContainsString('currentUser', $dql);
        $this->assertStringNotContainsString('managedMarkets', $dql);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 5. Tri et pagination
    // ─────────────────────────────────────────────────────────────────────────

    public function testPagination(): void
    {
        $dto = new AlertFilterDTO();
        $dto->setPage(3);
        $dto->setLimit(25);

        $qb = $this->service->buildQueryBuilder($dto, $this->createMockUser(UserRoleEnum::SUPERADMIN));
        $qb = $this->service->applyPaginationAndOrder($qb, $dto);

        $this->assertEquals(50, $qb->getFirstResult()); // (3-1) * 25
        $this->assertEquals(25, $qb->getMaxResults());
    }

    public function testTriParDefaut(): void
    {
        $dto = new AlertFilterDTO();

        $qb = $this->service->buildQueryBuilder($dto, $this->createMockUser(UserRoleEnum::SUPERADMIN));
        $qb = $this->service->applyPaginationAndOrder($qb, $dto);

        $dql = $qb->getQuery()->getDQL();
        $this->assertStringContainsString('a.dateCreation DESC', $dql);
    }

    public function testTriSpecifique(): void
    {
        $dto = new AlertFilterDTO();
        $dto->setTri('scoreGei');
        $dto->setTriSens('asc');

        $qb = $this->service->buildQueryBuilder($dto, $this->createMockUser(UserRoleEnum::SUPERADMIN));
        $qb = $this->service->applyPaginationAndOrder($qb, $dto);

        $dql = $qb->getQuery()->getDQL();
        $this->assertStringContainsString('a.scoreGei ASC', $dql);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function createMockUser(UserRoleEnum $role, int $id = 1): User
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);
        $user->setEmail("user{$id}@test.com");
        $user->setPrenom('Test');
        $user->setNom('User');
        $user->setRole($role);
        return $user;
    }
}