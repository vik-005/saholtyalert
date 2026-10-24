<?php

namespace App\Repository;

use App\Entity\Alert;
use App\Entity\Market;
use App\Entity\User;
use App\Enum\UserRoleEnum;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Alert>
 */
class AlertRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Alert::class);
    }

    /**
     * Agrégats cartographiques groupés par marché en deux requêtes au lieu de deux par marché.
     *
     * @return array<int, array{categories: array<string, int>, corridors: array<string, int>}>
     */
    public function getMapStatsByMarkets(array $marketIds, array $filters = []): array
    {
        $marketIds = array_values(array_unique(array_filter(array_map('intval', $marketIds))));
        if ($marketIds === []) {
            return [];
        }

        $connection = $this->getEntityManager()->getConnection();
        $marketParameters = [];
        $parameters = [];
        foreach ($marketIds as $index => $marketId) {
            $name = 'market_' . $index;
            $marketParameters[] = ':' . $name;
            $parameters[$name] = $marketId;
        }

        $conditions = ['a.market_id IN (' . implode(', ', $marketParameters) . ')', 'a.deleted_at IS NULL'];
        $filterColumns = [
            'statut' => 'a.statut',
            'categorie' => 'a.categorie',
            'priorite' => 'COALESCE(a.niveau_priorite_surcharge, a.niveau_priorite)',
            'urgence' => 'a.urgence',
            'typeAlerte' => 'a.type_alerte',
            'origine' => 'a.origine',
        ];
        foreach ($filterColumns as $key => $column) {
            if (!isset($filters[$key]) || $filters[$key] === '') {
                continue;
            }
            $name = 'filter_' . $key;
            $conditions[] = $column . ' = :' . $name;
            $parameters[$name] = $filters[$key];
        }
        foreach (['scoreMin' => '>=', 'scoreMax' => '<='] as $key => $operator) {
            if (!isset($filters[$key]) || $filters[$key] === '') {
                continue;
            }
            $name = 'filter_' . $key;
            $conditions[] = 'COALESCE(a.score_surcharge, a.score_gei) ' . $operator . ' :' . $name;
            $parameters[$name] = (int) $filters[$key];
        }
        if (!empty($filters['dateDebut'])) {
            $conditions[] = 'a.date_creation >= :filter_date_start';
            $parameters['filter_date_start'] = $filters['dateDebut'] . ' 00:00:00';
        }
        if (!empty($filters['dateFin'])) {
            $conditions[] = 'a.date_creation <= :filter_date_end';
            $parameters['filter_date_end'] = $filters['dateFin'] . ' 23:59:59';
        }

        $where = implode(' AND ', $conditions);
        $stats = [];
        $categories = $connection->executeQuery(
            'SELECT a.market_id AS marketId, a.categorie AS value, COUNT(a.id) AS cnt'
                . ' FROM alert a WHERE ' . $where
                . ' GROUP BY a.market_id, a.categorie ORDER BY a.market_id, cnt DESC',
            $parameters,
        )->fetchAllAssociative();

        foreach ($categories as $row) {
            if ($row['value'] === null) {
                continue;
            }
            $marketId = (int) $row['marketId'];
            $stats[$marketId]['categories'][$row['value']] = (int) $row['cnt'];
        }

        $corridors = $connection->executeQuery(
            'SELECT a.market_id AS marketId, a.port_corridor AS value, COUNT(a.id) AS cnt'
                . ' FROM alert a WHERE ' . $where
                . ' AND a.port_corridor IS NOT NULL AND a.port_corridor != :empty_corridor'
                . ' GROUP BY a.market_id, a.port_corridor ORDER BY a.market_id, cnt DESC',
            $parameters + ['empty_corridor' => ''],
        )->fetchAllAssociative();

        foreach ($corridors as $row) {
            $marketId = (int) $row['marketId'];
            $stats[$marketId]['corridors'][trim($row['value'])] = (int) $row['cnt'];
        }

        foreach ($marketIds as $marketId) {
            $stats[$marketId] ??= ['categories' => [], 'corridors' => []];
            $stats[$marketId]['categories'] ??= [];
            $stats[$marketId]['corridors'] ??= [];
        }

        return $stats;
    }

    public function findForUser(User $user, array $filters = [], int $page = 1, int $limit = 0): array
    {
        $qb = $this->createQueryBuilder('a')
            ->distinct()
            ->leftJoin('a.market', 'm')
            ->leftJoin('a.emetteur', 'e')
            ->leftJoin('a.validatedBy', 'vb')
            ->addSelect('m', 'e', 'vb');

        // Enforcement de la portée selon le rôle :
        if ($user->getRole() === \App\Enum\UserRoleEnum::EMETTEUR_TERRAIN) {
            // AGENT TERRAIN : voit uniquement ses propres alertes (espace dédié)
            $qb->andWhere('a.emetteur = :currentUser')
               ->setParameter('currentUser', $user);
        }

        // Filtres multiples
        if (!empty($filters['statut'])) {
            $qb->andWhere('a.statut = :statut')
               ->setParameter('statut', $filters['statut']);
        }

        if (!empty($filters['niveauPriorite'])) {
            $qb->andWhere('a.niveauPriorite = :niveau')
               ->setParameter('niveau', $filters['niveauPriorite']);
        }

        if (!empty($filters['typeAlerte'])) {
            $qb->andWhere('a.typeAlerte = :typeAlerte')
               ->setParameter('typeAlerte', $filters['typeAlerte']);
        }

        if (!empty($filters['market'])) {
            $qb->leftJoin('a.alertMarkets', 'am')
               ->andWhere('am.market = :filterMarket OR a.market = :filterMarket')
               ->setParameter('filterMarket', $filters['market'] instanceof Market ? $filters['market'] : $this->getEntityManager()->getReference(Market::class, (int) $filters['market']));
        }

        if (!empty($filters['categorie'])) {
            $qb->andWhere('a.categorie = :categorie')
               ->setParameter('categorie', $filters['categorie']);
        }

        if (!empty($filters['urgence'])) {
            $qb->andWhere('a.urgence = :urgence')
               ->setParameter('urgence', $filters['urgence']);
        }

        if (!empty($filters['search'])) {
            $qb->leftJoin('a.alertMarkets', 'ams')
               ->leftJoin('ams.market', 'ms')
               ->andWhere($this->normalizedSearchExpression('a.codeGei') . ' LIKE :search'
                   . ' OR ' . $this->normalizedSearchExpression('a.resumeExecutif') . ' LIKE :search'
                   . ' OR ' . $this->normalizedSearchExpression('a.portCorridor') . ' LIKE :search'
                   . ' OR ' . $this->normalizedSearchExpression('a.categorie') . ' LIKE :search'
                   . ' OR ' . $this->normalizedSearchExpression('m.nom') . ' LIKE :search'
                   . ' OR ' . $this->normalizedSearchExpression('m.codeIso3') . ' LIKE :search'
                   . ' OR ' . $this->normalizedSearchExpression('ms.nom') . ' LIKE :search'
                   . ' OR ' . $this->normalizedSearchExpression('ms.codeIso3') . ' LIKE :search')
               ->setParameter('search', '%' . $this->normalizeSearch((string) $filters['search']) . '%');
        }

        // Filtres étendus (Partie G)
        if (!empty($filters['scoreMin'])) {
            $qb->andWhere('(COALESCE(a.scoreSurcharge, a.scoreGei)) >= :scoreMin')
               ->setParameter('scoreMin', (int) $filters['scoreMin']);
        }

        if (!empty($filters['scoreMax'])) {
            $qb->andWhere('(COALESCE(a.scoreSurcharge, a.scoreGei)) <= :scoreMax')
               ->setParameter('scoreMax', (int) $filters['scoreMax']);
        }

        if (!empty($filters['origine'])) {
            $qb->andWhere('a.origine = :origine')
               ->setParameter('origine', $filters['origine']);
        }

        if (!empty($filters['dateDebut'])) {
            $qb->andWhere('a.dateCreation >= :dateDebut')
               ->setParameter('dateDebut', new \DateTime($filters['dateDebut'] . ' 00:00:00'));
        }

        if (!empty($filters['dateFin'])) {
            $qb->andWhere('a.dateCreation <= :dateFin')
               ->setParameter('dateFin', new \DateTime($filters['dateFin'] . ' 23:59:59'));
        }

        if (!empty($filters['anneeOperationnelle']) && ctype_digit((string) $filters['anneeOperationnelle'])) {
            $year = (int) $filters['anneeOperationnelle'];
            $qb->andWhere('a.dateCreation >= :yearStart AND a.dateCreation < :yearEnd')
               ->setParameter('yearStart', new \DateTime(sprintf('%d-01-01 00:00:00', $year)))
               ->setParameter('yearEnd', new \DateTime(sprintf('%d-01-01 00:00:00', $year + 1)));
        }

        // Urgence 72h uniquement (bascule Partie G.1)
        if (!empty($filters['urgence72h'])) {
            $qb->andWhere('a.urgence = :urg72h')
               ->setParameter('urg72h', '72h');
        }

        // Filtre par Agent soumetteur (Partie B) — nom réel via relation emetteur
        if (!empty($filters['agent'])) {
            $agentId = (int) $filters['agent'];
            if ($agentId > 0) {
                $qb->andWhere('a.emetteur = :filterAgent')
                   ->setParameter('filterAgent', $agentId);
            }
        }

        // Filtre par Manager validateur (Partie B) — nom réel via relation validatedBy
        if (!empty($filters['manager'])) {
            $managerId = (int) $filters['manager'];
            if ($managerId > 0) {
                $qb->andWhere('a.validatedBy = :filterManager')
                   ->setParameter('filterManager', $managerId);
            }
        }

        // Tri combinable (Partie G.1)
        $triChampsAutorisés = ['dateCreation', 'scoreGei', 'niveauPriorite', 'statut', 'market'];
        $triChamp = $filters['tri'] ?? 'dateCreation';
        $triSens  = strtoupper($filters['triSens'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
        if (!in_array($triChamp, $triChampsAutorisés, true)) {
            $triChamp = 'dateCreation';
        }

        // Exclure les alertes soft-delete
        $qb->andWhere('a.deletedAt IS NULL');

        $qb->orderBy('a.' . $triChamp, $triSens);

        if ($limit > 0) {
            $qb->setFirstResult(($page - 1) * $limit)
               ->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    private function normalizeSearch(string $value): string
    {
        return mb_strtolower(strtr(trim($value), [
            'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'à' => 'a', 'â' => 'a', 'ä' => 'a',
            'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'Î' => 'I', 'Ï' => 'I', 'î' => 'i', 'ï' => 'i', 'Ô' => 'O', 'Ö' => 'O', 'ô' => 'o', 'ö' => 'o',
            'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'Ç' => 'C', 'ç' => 'c',
        ]));
    }

    private function normalizedSearchExpression(string $field): string
    {
        return 'LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE('
            . $field . ", 'à', 'a'), 'â', 'a'), 'ä', 'a'), 'é', 'e'), 'è', 'e'), 'ê', 'e'), 'ë', 'e'), 'ç', 'c'), 'ô', 'o'))";
    }

    public function findForExport(?string $pays = null, ?string $statut = null, ?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->leftJoin('a.market', 'm')
            ->leftJoin('a.emetteur', 'e')
            ->leftJoin('a.responsableSuivi', 'r')
            ->leftJoin('a.validatedBy', 'vb')
            ->addSelect('m', 'e', 'r', 'vb');

        if ($pays) {
            $qb->andWhere('m.codeIso3 = :pays OR m.nom = :pays')
               ->setParameter('pays', $pays);
        }

        if ($statut) {
            $qb->andWhere('a.statut = :statut')
               ->setParameter('statut', $statut);
        }

        if ($from) {
            $qb->andWhere('a.dateCreation >= :from')
               ->setParameter('from', $from);
        }

        if ($to) {
            $qb->andWhere('a.dateCreation <= :to')
               ->setParameter('to', $to);
        }

        // Exclure les alertes soft-delete
        $qb->andWhere('a.deletedAt IS NULL');

        return $qb->orderBy('a.dateCreation', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function getKpiStats(?User $user = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.deletedAt IS NULL');

        $qbCritiques = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.niveauPriorite = :v AND a.deletedAt IS NULL')
            ->setParameter('v', 'critique');

        $qbActionnables = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.exploitabilite = :v AND a.deletedAt IS NULL')
            ->setParameter('v', 'actionnable');

        $qbTransmis = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.transmission = :v AND a.deletedAt IS NULL')
            ->setParameter('v', 'oui');

        if ($user !== null && $user->getRole() === UserRoleEnum::EMETTEUR_TERRAIN) {
            $qb->andWhere('a.emetteur = :user')->setParameter('user', $user);
            $qbCritiques->andWhere('a.emetteur = :user')->setParameter('user', $user);
            $qbActionnables->andWhere('a.emetteur = :user')->setParameter('user', $user);
            $qbTransmis->andWhere('a.emetteur = :user')->setParameter('user', $user);
        }

        $total        = (int) $qb->getQuery()->getSingleScalarResult();
        $critiques    = (int) $qbCritiques->getQuery()->getSingleScalarResult();
        $actionnables = (int) $qbActionnables->getQuery()->getSingleScalarResult();
        $transmis     = (int) $qbTransmis->getQuery()->getSingleScalarResult();

        return [
            'total'       => $total,
            'critiques'   => $critiques,
            'actionnables'=> $actionnables,
            'transmis'    => $transmis,
        ];
    }

    /**
     * Compte les alertes par niveau de priorité — pour le donut Chart.js.
     */
    public function countByNiveauPriorite(?User $user = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->select('a.niveauPriorite AS niveau, COUNT(a.id) AS cnt')
            ->where('a.niveauPriorite IS NOT NULL')
            ->andWhere('a.deletedAt IS NULL');

        if ($user !== null && $user->getRole() === UserRoleEnum::EMETTEUR_TERRAIN) {
            $qb->andWhere('a.emetteur = :user')->setParameter('user', $user);
        }

        $rows = $qb->groupBy('a.niveauPriorite')
            ->getQuery()
            ->getArrayResult();

        $result = ['critique' => 0, 'eleve' => 0, 'modere' => 0, 'faible' => 0];
        foreach ($rows as $row) {
            $key = $row['niveau'] instanceof \App\Enum\NiveauPriorite
                ? $row['niveau']->value
                : (string) $row['niveau'];
            if (isset($result[$key])) {
                $result[$key] = (int) $row['cnt'];
            }
        }
        return $result;
    }

    /**
     * Compte les alertes par pays (Market) — pour la barre par pays.
     * Retourne [['nom' => 'Bénin', 'count' => 12], ...]
     */
    public function countByPays(?User $user = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->select('m.nom AS nom, COUNT(a.id) AS cnt')
            ->join('a.market', 'm')
            ->where('a.deletedAt IS NULL');

        if ($user !== null && $user->getRole() === UserRoleEnum::EMETTEUR_TERRAIN) {
            $qb->andWhere('a.emetteur = :user')->setParameter('user', $user);
        }

        $rows = $qb->groupBy('m.id')
            ->orderBy('cnt', 'DESC')
            ->setMaxResults(6)
            ->getQuery()
            ->getArrayResult();

        return array_map(fn($r) => ['nom' => $r['nom'], 'count' => (int) $r['cnt']], $rows);
    }

    /**
     * Score GEI moyen sur toutes les alertes scorées.
     */
    public function getScoreMoyen(?User $user = null): float
    {
        $qb = $this->createQueryBuilder('a')
            ->select('AVG(a.scoreGei)')
            ->where('a.scoreGei IS NOT NULL')
            ->andWhere('a.deletedAt IS NULL');

        if ($user !== null && $user->getRole() === UserRoleEnum::EMETTEUR_TERRAIN) {
            $qb->andWhere('a.emetteur = :user')->setParameter('user', $user);
        }

        $avg = $qb->getQuery()->getSingleScalarResult();

        return round((float) $avg, 1);
    }

    /**
     * Données d'évolution pour le graphique line chart.
     * Retourne labels (J-N), nouvelles alertes par jour, transmises par jour.
     *
     * @param int $days Nombre de jours en arrière
     */
    public function getEvolutionData(int $days = 30, ?User $user = null): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $debut = (new \DateTime())->modify("-{$days} days")->setTime(0, 0)->format('Y-m-d H:i:s');

        $params = ['debut' => $debut];
        $emetteurFilter = '';
        if ($user !== null && $user->getRole() === UserRoleEnum::EMETTEUR_TERRAIN) {
            $emetteurFilter = 'AND a.emetteur_id = :emetteurId';
            $params['emetteurId'] = (int) $user->getId();
        }

        $rows = $conn->fetchAllAssociative(
            "SELECT DATE(a.date_creation) AS jour,
                    COUNT(*) AS nb,
                    SUM(CASE WHEN a.transmission = 'oui' THEN 1 ELSE 0 END) AS nb_transmises
             FROM alert a
             WHERE a.date_creation >= :debut
               AND a.deleted_at IS NULL
             {$emetteurFilter}
             GROUP BY DATE(a.date_creation)
             ORDER BY jour ASC",
            $params
        );

        $nouvellesMap = [];
        $transmisesMap = [];
        foreach ($rows as $row) {
            $nouvellesMap[$row['jour']] = (int) $row['nb'];
            $transmisesMap[$row['jour']] = (int) $row['nb_transmises'];
        }

        $labels     = [];
        $nouvelles  = [];
        $transmises = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = (new \DateTime())->modify("-{$i} days");
            $dateStr = $date->format('Y-m-d');
            $labels[] = $i === 0 ? "Auj." : $date->format('d/m');
            $nouvelles[]  = $nouvellesMap[$dateStr] ?? 0;
            $transmises[] = $transmisesMap[$dateStr] ?? 0;
        }

        return [
            'labels'     => $labels,
            'nouvelles'  => $nouvelles,
            'transmises' => $transmises,
        ];
    }

    /**
     * Répartition des alertes par catégorie pour un marché donné.
     * Utilisé par l'API carte pour remplir les popups.
     *
     * @return array<string, int>  ['saisie' => 12, 'transit_tabac' => 5, ...]
     */
    public function getCategorieStatsByMarket(int $marketId, array $filters = []): array
    {
        $qb = $this->createQueryBuilder('a')
            ->select('a.categorie AS cat', 'COUNT(a.id) AS cnt')
            ->where('IDENTITY(a.market) = :marketId AND a.deletedAt IS NULL')
            ->setParameter('marketId', $marketId)
            ->groupBy('a.categorie');

        if (!empty($filters['statut'])) {
            $qb->andWhere('a.statut = :statut')->setParameter('statut', $filters['statut']);
        }
        if (!empty($filters['categorie'])) {
            $qb->andWhere('a.categorie = :categorie')->setParameter('categorie', $filters['categorie']);
        }
        if (!empty($filters['priorite'])) {
            $qb->andWhere('COALESCE(a.niveauPrioriteSurcharge, a.niveauPriorite) = :prio')->setParameter('prio', $filters['priorite']);
        }
        if (!empty($filters['urgence'])) {
            $qb->andWhere('a.urgence = :catUrgence')->setParameter('catUrgence', $filters['urgence']);
        }
        if (!empty($filters['typeAlerte'])) {
            $qb->andWhere('a.typeAlerte = :catTypeAlerte')->setParameter('catTypeAlerte', $filters['typeAlerte']);
        }
        if (!empty($filters['scoreMin'])) {
            $qb->andWhere('COALESCE(a.scoreSurcharge, a.scoreGei) >= :catScoreMin')->setParameter('catScoreMin', (int) $filters['scoreMin']);
        }
        if (!empty($filters['scoreMax'])) {
            $qb->andWhere('COALESCE(a.scoreSurcharge, a.scoreGei) <= :catScoreMax')->setParameter('catScoreMax', (int) $filters['scoreMax']);
        }
        if (!empty($filters['origine'])) {
            $qb->andWhere('a.origine = :catOrigine')->setParameter('catOrigine', $filters['origine']);
        }
        if (!empty($filters['dateDebut'])) {
            $qb->andWhere('a.dateCreation >= :debut')
               ->setParameter('debut', new \DateTime($filters['dateDebut'] . ' 00:00:00'));
        }
        if (!empty($filters['dateFin'])) {
            $qb->andWhere('a.dateCreation <= :fin')
               ->setParameter('fin', new \DateTime($filters['dateFin'] . ' 23:59:59'));
        }

        $rows = $qb->getQuery()->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if ($row['cat'] !== null) {
                $result[$row['cat']] = (int) $row['cnt'];
            }
        }
        arsort($result);
        return $result;
    }

    /**
     * Répartition des alertes par corridor pour un marché donné.
     * @return array<string, int>  ['Port de Cotonou' => 5, 'corridor Lomé' => 3, ...]
     */
    public function getCorridorStatsByMarket(int $marketId, array $filters = []): array
    {
        $qb = $this->createQueryBuilder('a')
            ->select('a.portCorridor AS corridor', 'COUNT(a.id) AS cnt')
            ->where('IDENTITY(a.market) = :marketId AND a.deletedAt IS NULL AND a.portCorridor IS NOT NULL AND a.portCorridor != :empty')
            ->setParameter('marketId', $marketId)
            ->setParameter('empty', '')
            ->groupBy('a.portCorridor')
            ->orderBy('cnt', 'DESC');

        if (!empty($filters['statut'])) {
            $qb->andWhere('a.statut = :statut')->setParameter('statut', $filters['statut']);
        }
        if (!empty($filters['categorie'])) {
            $qb->andWhere('a.categorie = :categorie')->setParameter('categorie', $filters['categorie']);
        }
        if (!empty($filters['priorite'])) {
            $qb->andWhere('COALESCE(a.niveauPrioriteSurcharge, a.niveauPriorite) = :prio')->setParameter('prio', $filters['priorite']);
        }
        if (!empty($filters['urgence'])) {
            $qb->andWhere('a.urgence = :corrUrgence')->setParameter('corrUrgence', $filters['urgence']);
        }
        if (!empty($filters['typeAlerte'])) {
            $qb->andWhere('a.typeAlerte = :corrTypeAlerte')->setParameter('corrTypeAlerte', $filters['typeAlerte']);
        }
        if (!empty($filters['scoreMin'])) {
            $qb->andWhere('COALESCE(a.scoreSurcharge, a.scoreGei) >= :corrScoreMin')->setParameter('corrScoreMin', (int) $filters['scoreMin']);
        }
        if (!empty($filters['scoreMax'])) {
            $qb->andWhere('COALESCE(a.scoreSurcharge, a.scoreGei) <= :corrScoreMax')->setParameter('corrScoreMax', (int) $filters['scoreMax']);
        }
        if (!empty($filters['origine'])) {
            $qb->andWhere('a.origine = :corrOrigine')->setParameter('corrOrigine', $filters['origine']);
        }
        if (!empty($filters['dateDebut'])) {
            $qb->andWhere('a.dateCreation >= :debut')
               ->setParameter('debut', new \DateTime($filters['dateDebut'] . ' 00:00:00'));
        }
        if (!empty($filters['dateFin'])) {
            $qb->andWhere('a.dateCreation <= :fin')
               ->setParameter('fin', new \DateTime($filters['dateFin'] . ' 23:59:59'));
        }

        $rows = $qb->getQuery()->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            if ($row['corridor'] !== null) {
                $result[trim($row['corridor'])] = (int) $row['cnt'];
            }
        }
        return $result;
    }

    /**
     * Délai moyen de traitement en heures (soumission createdAt → date_validation).
     * Utilisé par le dashboard KPI (Partie D).
     * Retourne null si aucune alerte validée.
     */
    public function getAvgDelaiTraitementHeures(?User $user = null): ?float
    {
        $conn = $this->getEntityManager()->getConnection();

        $whereUser = '';
        $params = [];
        if ($user !== null && $user->getRole() === \App\Enum\UserRoleEnum::EMETTEUR_TERRAIN) {
            $whereUser = 'AND a.emetteur_id = :userId';
            $params['userId'] = $user->getId();
        } elseif ($user !== null && $user->getRole() === \App\Enum\UserRoleEnum::PFT) {
            $whereUser = 'AND a.validated_by_id = :userId';
            $params['userId'] = $user->getId();
        }

        $result = $conn->fetchOne(
            "SELECT AVG(TIMESTAMPDIFF(SECOND, a.created_at, a.date_validation)) / 3600
             FROM alert a
             WHERE a.date_validation IS NOT NULL
               AND a.deleted_at IS NULL
               {$whereUser}",
            $params
        );

        return $result !== false && $result !== null ? round((float) $result, 1) : null;
    }

    /**
     * Liste les Agents (EMETTEUR_TERRAIN) visibles par l'utilisateur courant.
     * Utilisé pour peupler le filtre "Agent" du registre (Partie B).
     *
     * @return User[]
     */
    public function findAgentsForFilter(User $viewer): array
    {
        if (!in_array($viewer->getRole(), [
            \App\Enum\UserRoleEnum::PFT,
            \App\Enum\UserRoleEnum::SAHOLTY,
            \App\Enum\UserRoleEnum::SUPERADMIN,
        ], true)) {
            return [];
        }

        $em = $this->getEntityManager();
        $qb = $em->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.role = :role')
            ->setParameter('role', \App\Enum\UserRoleEnum::EMETTEUR_TERRAIN->value)
            ->orderBy('u.nom', 'ASC');

        return $qb->getQuery()->getResult();
    }

    /**
     * Liste les Managers (PFT/SAHOLTY) qui ont validé au moins une alerte visible.
     * Utilisé pour peupler le filtre "Manager" du registre (Partie B).
     *
     * @return User[]
     */
    public function findManagersForFilter(User $viewer): array
    {
        if (!in_array($viewer->getRole(), [
            \App\Enum\UserRoleEnum::PFT,
            \App\Enum\UserRoleEnum::SAHOLTY,
            \App\Enum\UserRoleEnum::SUPERADMIN,
        ], true)) {
            return [];
        }

        $em = $this->getEntityManager();
        $qb = $em->createQueryBuilder()
            ->select('u')
            ->distinct(true)
            ->from(User::class, 'u')
            ->join(Alert::class, 'a', 'WITH', 'a.validatedBy = u')
            ->where('a.deletedAt IS NULL')
            ->orderBy('u.nom', 'ASC');

        return $qb->getQuery()->getResult();
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // DASHBOARD AGENT — méthodes filtrées strictement par emetteur
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Répartition des alertes par catégorie pour un Agent donné.
     * Retourne tableau trié par count DESC : [['categorie' => '...', 'count' => n], ...]
     */
    public function countByCategorieForAgent(User $agent): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('a.categorie AS cat, COUNT(a.id) AS cnt')
            ->where('a.emetteur = :agent')
            ->andWhere('a.deletedAt IS NULL')
            ->andWhere('a.categorie IS NOT NULL')
            ->setParameter('agent', $agent)
            ->groupBy('a.categorie')
            ->orderBy('cnt', 'DESC')
            ->getQuery()
            ->getArrayResult();

        return array_map(fn($r) => ['categorie' => (string) $r['cat'], 'count' => (int) $r['cnt']], $rows);
    }

    /**
     * Taux de validation des alertes soumises par un Agent.
     * Retourne un tableau avec total, validees, rejetees, en_attente et le taux en %.
     */
    public function getTauxValidationAgent(User $agent): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('a.statut AS statut, COUNT(a.id) AS cnt')
            ->where('a.emetteur = :agent')
            ->andWhere('a.deletedAt IS NULL')
            ->setParameter('agent', $agent)
            ->groupBy('a.statut')
            ->getQuery()
            ->getArrayResult();

        $counts = ['total' => 0, 'validee' => 0, 'a_completer' => 0, 'nouveau' => 0, 'a_valider_saholty' => 0, 'transmis' => 0];
        foreach ($rows as $row) {
            $key = $row['statut'] instanceof \App\Enum\AlertStatut ? $row['statut']->value : (string) $row['statut'];
            $counts['total'] += (int) $row['cnt'];
            if (isset($counts[$key])) {
                $counts[$key] = (int) $row['cnt'];
            }
        }

        $counts['taux_validation'] = $counts['total'] > 0
            ? round(($counts['validee'] / $counts['total']) * 100, 1)
            : 0.0;

        $counts['en_cours'] = $counts['a_valider_saholty'];

        return $counts;
    }

    /**
     * Répartition des alertes par niveau d'urgence pour un Agent.
     * Retourne [['urgence' => '72h', 'count' => n], ...]
     */
    public function countByUrgenceForAgent(User $agent): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('a.urgence AS urgence, COUNT(a.id) AS cnt')
            ->where('a.emetteur = :agent')
            ->andWhere('a.deletedAt IS NULL')
            ->andWhere('a.urgence IS NOT NULL')
            ->setParameter('agent', $agent)
            ->groupBy('a.urgence')
            ->orderBy('cnt', 'DESC')
            ->getQuery()
            ->getArrayResult();

        return array_map(fn($r) => [
            'urgence' => $r['urgence'] instanceof \App\Enum\AlertUrgence ? $r['urgence']->value : (string) $r['urgence'],
            'count'   => (int) $r['cnt'],
        ], $rows);
    }

    /**
     * Score GEI moyen (effectif : surcharge ou calculé) pour un Agent.
     */
    public function getScoreMoyenAgent(User $agent): float
    {
        $avg = $this->createQueryBuilder('a')
            ->select('AVG(COALESCE(a.scoreSurcharge, a.scoreGei))')
            ->where('a.emetteur = :agent')
            ->andWhere('a.deletedAt IS NULL')
            ->andWhere('a.scoreGei IS NOT NULL')
            ->setParameter('agent', $agent)
            ->getQuery()
            ->getSingleScalarResult();

        return round((float) $avg, 1);
    }

    /**
     * Distribution des scores GEI pour un Agent (par tranches).
     * Retourne ['1-9' => n, '10-13' => n, '14-17' => n, '18+' => n]
     */
    public function getScoreDistributionAgent(User $agent): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $result = $conn->fetchAllAssociative(
            "SELECT
                SUM(CASE WHEN COALESCE(score_surcharge, score_gei) BETWEEN 1  AND 9  THEN 1 ELSE 0 END) AS faible,
                SUM(CASE WHEN COALESCE(score_surcharge, score_gei) BETWEEN 10 AND 13 THEN 1 ELSE 0 END) AS modere,
                SUM(CASE WHEN COALESCE(score_surcharge, score_gei) BETWEEN 14 AND 17 THEN 1 ELSE 0 END) AS eleve,
                SUM(CASE WHEN COALESCE(score_surcharge, score_gei) >= 18             THEN 1 ELSE 0 END) AS critique
             FROM alert
             WHERE emetteur_id = :agentId AND deleted_at IS NULL AND score_gei IS NOT NULL",
            ['agentId' => (int) $agent->getId()]
        );

        $row = $result[0] ?? [];
        return [
            'faible'   => (int) ($row['faible']   ?? 0),
            'modere'   => (int) ($row['modere']   ?? 0),
            'eleve'    => (int) ($row['eleve']    ?? 0),
            'critique' => (int) ($row['critique'] ?? 0),
        ];
    }

    /**
     * Évolution mensuelle des soumissions d'un Agent sur les 12 derniers mois.
     * Retourne ['labels' => [...], 'soumises' => [...], 'validees' => [...]]
     */
    public function getEvolutionMensuelleAgent(User $agent): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $rows = $conn->fetchAllAssociative(
            "SELECT DATE_FORMAT(date_creation, '%Y-%m') AS mois, COUNT(*) AS nb
             FROM alert
             WHERE emetteur_id = :agentId
               AND deleted_at IS NULL
               AND date_creation >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
             GROUP BY mois ORDER BY mois ASC",
            ['agentId' => (int) $agent->getId()]
        );

        $rowsV = $conn->fetchAllAssociative(
            "SELECT DATE_FORMAT(date_creation, '%Y-%m') AS mois, COUNT(*) AS nb
             FROM alert
             WHERE emetteur_id = :agentId
               AND deleted_at IS NULL
               AND statut = 'validee'
               AND date_creation >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
             GROUP BY mois ORDER BY mois ASC",
            ['agentId' => (int) $agent->getId()]
        );

        $soumisesMap = [];
        foreach ($rows as $r) { $soumisesMap[$r['mois']] = (int) $r['nb']; }
        $valideesMap = [];
        foreach ($rowsV as $r) { $valideesMap[$r['mois']] = (int) $r['nb']; }

        $labels = $soumises = $validees = [];
        for ($i = 11; $i >= 0; $i--) {
            $dt = (new \DateTime())->modify("-{$i} months");
            $key = $dt->format('Y-m');
            $labels[]   = $dt->format('M Y');
            $soumises[] = $soumisesMap[$key] ?? 0;
            $validees[] = $valideesMap[$key] ?? 0;
        }

        return ['labels' => $labels, 'soumises' => $soumises, 'validees' => $validees];
    }

    /**
     * Dernières alertes soumises par un Agent (pour l'activité récente).
     *
     * @return Alert[]
     */
    public function findRecentByAgent(User $agent, int $limit = 8): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.emetteur = :agent')
            ->andWhere('a.deletedAt IS NULL')
            ->setParameter('agent', $agent)
            ->orderBy('a.dateCreation', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
