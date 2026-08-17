<?php

namespace App\Repository;

use App\Entity\Alert;
use App\Entity\Market;
use App\Entity\User;
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

    public function findForUser(User $user, array $filters = []): array
    {
        $qb = $this->createQueryBuilder('a')
            ->leftJoin('a.market', 'm')
            ->leftJoin('a.emetteur', 'e')
            ->addSelect('m', 'e');

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

        return $qb->orderBy('a.dateCreation', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findForExport(?string $pays = null, ?string $statut = null, ?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->leftJoin('a.market', 'm')
            ->leftJoin('a.emetteur', 'e')
            ->leftJoin('a.responsableSuivi', 'r')
            ->addSelect('m', 'e', 'r');

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

        return $qb->orderBy('a.dateCreation', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function getKpiStats(): array
    {
        $total        = (int) $this->createQueryBuilder('a')->select('COUNT(a.id)')->getQuery()->getSingleScalarResult();
        $critiques    = (int) $this->createQueryBuilder('a')->select('COUNT(a.id)')->where('a.niveauPriorite = :v')->setParameter('v', 'critique')->getQuery()->getSingleScalarResult();
        $actionnables = (int) $this->createQueryBuilder('a')->select('COUNT(a.id)')->where('a.exploitabilite = :v')->setParameter('v', 'actionnable')->getQuery()->getSingleScalarResult();
        $transmis     = (int) $this->createQueryBuilder('a')->select('COUNT(a.id)')->where('a.transmission = :v')->setParameter('v', 'oui')->getQuery()->getSingleScalarResult();

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
    public function countByNiveauPriorite(): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('a.niveauPriorite AS niveau, COUNT(a.id) AS cnt')
            ->where('a.niveauPriorite IS NOT NULL')
            ->groupBy('a.niveauPriorite')
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
    public function countByPays(): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('m.nom AS nom, COUNT(a.id) AS cnt')
            ->join('a.market', 'm')
            ->groupBy('m.id')
            ->orderBy('cnt', 'DESC')
            ->setMaxResults(6)
            ->getQuery()
            ->getArrayResult();

        return array_map(fn($r) => ['nom' => $r['nom'], 'count' => (int) $r['cnt']], $rows);
    }

    /**
     * Score GEI moyen sur toutes les alertes scorées.
     */
    public function getScoreMoyen(): float
    {
        $avg = $this->createQueryBuilder('a')
            ->select('AVG(a.scoreGei)')
            ->where('a.scoreGei IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();

        return round((float) $avg, 1);
    }

    /**
     * Données d'évolution pour le graphique line chart.
     * Retourne labels (J-N), nouvelles alertes par jour, transmises par jour.
     *
     * @param int $days Nombre de jours en arrière
     */
    public function getEvolutionData(int $days = 30): array
    {
        $labels     = [];
        $nouvelles  = [];
        $transmises = [];

        $conn = $this->getEntityManager()->getConnection();

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = (new \DateTime())->modify("-{$i} days");
            $dateStr = $date->format('Y-m-d');

            // Label : "J" format court
            $labels[] = $i === 0 ? "Auj." : $date->format('d/m');

            // Nouvelles alertes ce jour
            $n = (int) $conn->fetchOne(
                'SELECT COUNT(*) FROM alert WHERE DATE(date_creation) = :d',
                ['d' => $dateStr]
            );
            $nouvelles[] = $n;

            // Transmises ce jour
            $t = (int) $conn->fetchOne(
                "SELECT COUNT(*) FROM alert WHERE DATE(date_creation) = :d AND transmission = 'oui'",
                ['d' => $dateStr]
            );
            $transmises[] = $t;
        }

        return [
            'labels'     => $labels,
            'nouvelles'  => $nouvelles,
            'transmises' => $transmises,
        ];
    }
}
