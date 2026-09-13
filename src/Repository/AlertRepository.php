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

    public function findForUser(User $user, array $filters = [], int $page = 1, int $limit = 0): array
    {
        $qb = $this->createQueryBuilder('a')
            ->leftJoin('a.market', 'm')
            ->leftJoin('a.emetteur', 'e')
            ->leftJoin('a.validatedBy', 'vb')
            ->addSelect('m', 'e', 'vb');

        // Enforcement de la portée selon le rôle :
        if ($user->getRole() === \App\Enum\UserRoleEnum::EMETTEUR_TERRAIN) {
            // AGENT : ses propres alertes uniquement
            $qb->andWhere('a.emetteur = :currentUser')
               ->setParameter('currentUser', $user);
        } elseif ($user->getRole() === \App\Enum\UserRoleEnum::PFT) {
            // MANAGER : alertes de ses marchés gérés
            $managedMarkets = $user->getAllManagedMarkets();
            if (!empty($managedMarkets)) {
                $qb->andWhere('a.market IN (:managedMarkets)')
                   ->setParameter('managedMarkets', $managedMarkets);
            }
        }
        // SUPERADMIN, SAHOLTY, COMITE_AIT, SECRETARIAT_GEI → toutes les alertes (pas de filtre)

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
            $qb->andWhere('a.market = :filterMarket')
               ->setParameter('filterMarket', $filters['market']);
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
            $qb->andWhere('a.codeGei LIKE :search OR a.resumeExecutif LIKE :search OR a.portCorridor LIKE :search')
               ->setParameter('search', '%' . $filters['search'] . '%');
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
            ->where('a.niveauPriorite IS NOT NULL');

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
            ->join('a.market', 'm');

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
            ->where('a.scoreGei IS NOT NULL');

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
        $debut = (new \DateTime())->modify("-{$days} days")->format('Y-m-d');

        $emetteurFilter = ($user !== null && $user->getRole() === UserRoleEnum::EMETTEUR_TERRAIN)
            ? 'AND a.emetteur_id = ' . (int) $user->getId()
            : '';

        $rows = $conn->fetchAllAssociative(
            "SELECT DATE(a.date_creation) AS jour, COUNT(*) AS nb
             FROM alert a
             WHERE DATE(a.date_creation) >= :debut
             {$emetteurFilter}
             GROUP BY DATE(a.date_creation)
             ORDER BY jour ASC",
            ['debut' => $debut]
        );

        $nouvellesMap = [];
        foreach ($rows as $row) {
            $nouvellesMap[$row['jour']] = (int) $row['nb'];
        }

        $rowsTx = $conn->fetchAllAssociative(
            "SELECT DATE(a.date_creation) AS jour, COUNT(*) AS nb
             FROM alert a
             WHERE DATE(a.date_creation) >= :debut AND a.transmission = 'oui'
             {$emetteurFilter}
             GROUP BY DATE(a.date_creation)
             ORDER BY jour ASC",
            ['debut' => $debut]
        );

        $transmisesMap = [];
        foreach ($rowsTx as $row) {
            $transmisesMap[$row['jour']] = (int) $row['nb'];
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
            ->where('a.market = :marketId AND a.deletedAt IS NULL')
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
            ->where('a.market = :marketId AND a.deletedAt IS NULL AND a.portCorridor IS NOT NULL AND a.portCorridor != :empty')
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
        $em = $this->getEntityManager();
        $qb = $em->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.role = :role')
            ->setParameter('role', \App\Enum\UserRoleEnum::EMETTEUR_TERRAIN->value)
            ->orderBy('u.nom', 'ASC');

        if ($viewer->getRole() === \App\Enum\UserRoleEnum::PFT) {
            // Limiter aux Agents rattachés aux marchés du Manager (via agent_marches ou market legacy)
            $managedIds = array_map(fn($m) => $m->getId(), $viewer->getAllManagedMarkets());
            if (!empty($managedIds)) {
                $qb->leftJoin('u.agentMarkets', 'am')
                   ->andWhere('am.id IN (:mids) OR u.market IN (:mids)')
                   ->setParameter('mids', $managedIds);
            } else {
                $qb->andWhere('1 = 0'); // Aucun marché géré → aucun agent visible
            }
        }
        // SUPERADMIN, SAHOLTY → tous les agents

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
        $em = $this->getEntityManager();
        $qb = $em->createQueryBuilder()
            ->select('u')
            ->distinct(true)
            ->from(User::class, 'u')
            ->join(Alert::class, 'a', 'WITH', 'a.validatedBy = u')
            ->where('a.deletedAt IS NULL')
            ->orderBy('u.nom', 'ASC');

        if ($viewer->getRole() === \App\Enum\UserRoleEnum::PFT) {
            $managedIds = array_map(fn($m) => $m->getId(), $viewer->getAllManagedMarkets());
            if (!empty($managedIds)) {
                $qb->join('a.market', 'fm')
                   ->andWhere('fm.id IN (:mids)')
                   ->setParameter('mids', $managedIds);
            } else {
                $qb->andWhere('1 = 0');
            }
        }

        return $qb->getQuery()->getResult();
    }
}
