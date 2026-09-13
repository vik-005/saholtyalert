<?php

namespace App\Service;

use App\Entity\Alert;
use App\Entity\Urgence72hCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Service KPI — agrégations et requêtes pour le tableau de bord stratégique.
 *
 * Règles d'implémentation :
 *  - DQL (createQueryBuilder) : COUNT, AVG, GROUP BY sur colonnes scalaires uniquement.
 *  - SQL natif DBAL (getConnection()->fetchAllAssociative) : dès qu'on a besoin de
 *    fonctions SQL non supportées en DQL (DATE, YEAR, WEEK, CONCAT, sous-requêtes complexes).
 *  - Jamais de DATE() en DQL — erreur "Expected known function".
 *  - Toutes les requêtes sont mises en cache (TTL 300s / 5 min).
 */
class KPIService
{
    private const CACHE_TTL = 300;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CacheInterface         $cache,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // F.1 — CARTES KPI PRINCIPALES
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Alertes soumises sur la période + comparaison période précédente.
     */
    public function getAlertesSoumises(\DateTime $debut, \DateTime $fin, array $marches): array
    {
        $key = 'kpi_alertes_soumises_' . md5($debut->format('Ymd') . $fin->format('Ymd') . implode(',', $marches));

        return $this->cache->get($key, function (ItemInterface $item) use ($debut, $fin, $marches): array {
            $item->expiresAfter(self::CACHE_TTL);

            $courant = $this->countAlertesParPeriode($debut, $fin, $marches);

            $duree          = max(1, $fin->diff($debut)->days + 1);
            $debutPrec      = (clone $debut)->sub(new \DateInterval("P{$duree}D"));
            $finPrec        = (clone $debut)->sub(new \DateInterval('P1D'));
            $precedent      = $this->countAlertesParPeriode($debutPrec, $finPrec, $marches);

            $variation = $precedent > 0 ? round(($courant - $precedent) / $precedent * 100, 1) : 0;

            return [
                'value'     => $courant,
                'previous'  => $precedent,
                'variation' => $variation,
                'trend'     => $variation > 0 ? 'up' : ($variation < 0 ? 'down' : 'flat'),
            ];
        });
    }

    /**
     * Alertes traitées (validées + archive + clos) sur la période.
     */
    public function getAlerteesTraitees(\DateTime $debut, \DateTime $fin, array $marches): array
    {
        $key = 'kpi_alertes_traitees_' . md5($debut->format('Ymd') . $fin->format('Ymd') . implode(',', $marches));

        return $this->cache->get($key, function (ItemInterface $item) use ($debut, $fin, $marches): array {
            $item->expiresAfter(self::CACHE_TTL);

            $courant   = $this->countAlertesTraiteesParPeriode($debut, $fin, $marches);
            $duree     = max(1, $fin->diff($debut)->days + 1);
            $debutPrec = (clone $debut)->sub(new \DateInterval("P{$duree}D"));
            $finPrec   = (clone $debut)->sub(new \DateInterval('P1D'));
            $precedent = $this->countAlertesTraiteesParPeriode($debutPrec, $finPrec, $marches);

            $variation = $precedent > 0 ? round(($courant - $precedent) / $precedent * 100, 1) : 0;

            return [
                'value'     => $courant,
                'previous'  => $precedent,
                'variation' => $variation,
                'trend'     => $variation > 0 ? 'up' : ($variation < 0 ? 'down' : 'flat'),
            ];
        });
    }

    /**
     * Taux de transmission (%) sur la période.
     */
    public function getTauxTransmission(\DateTime $debut, \DateTime $fin, array $marches): array
    {
        $key = 'kpi_taux_tx_' . md5($debut->format('Ymd') . $fin->format('Ymd') . implode(',', $marches));

        return $this->cache->get($key, function (ItemInterface $item) use ($debut, $fin, $marches): array {
            $item->expiresAfter(self::CACHE_TTL);

            $qb = $this->em->createQueryBuilder()->from(Alert::class, 'a');

            $transmises = (int) (clone $qb)
                ->select('COUNT(a.id)')
                ->where('a.statut = :tx')
                ->andWhere('a.dateCreation BETWEEN :d AND :f')
                ->andWhere('a.market IN (:m)')
                ->setParameter('tx', 'validee')
                ->setParameter('d', $debut)
                ->setParameter('f', $fin)
                ->setParameter('m', $marches)
                ->getQuery()->getSingleScalarResult();

            $total = (int) (clone $qb)
                ->select('COUNT(a.id)')
                ->where('a.statut IN (:statuts)')
                ->andWhere('a.dateCreation BETWEEN :d AND :f')
                ->andWhere('a.market IN (:m)')
                ->setParameter('statuts', ['validee', 'archive'])
                ->setParameter('d', $debut)
                ->setParameter('f', $fin)
                ->setParameter('m', $marches)
                ->getQuery()->getSingleScalarResult();

            return [
                'value' => $total > 0 ? round($transmises / $total * 100, 1) : 0,
                'count' => $transmises,
                'total' => $total,
            ];
        });
    }

    /**
     * Score GEI moyen sur la période.
     */
    public function getScoreMoyen(\DateTime $debut, \DateTime $fin, array $marches): array
    {
        $key = 'kpi_score_moyen_' . md5($debut->format('Ymd') . $fin->format('Ymd') . implode(',', $marches));

        return $this->cache->get($key, function (ItemInterface $item) use ($debut, $fin, $marches): array {
            $item->expiresAfter(self::CACHE_TTL);

            $avg = $this->em->createQueryBuilder()
                ->select('AVG(a.scoreGei)')
                ->from(Alert::class, 'a')
                ->where('a.dateCreation BETWEEN :d AND :f')
                ->andWhere('a.market IN (:m)')
                ->andWhere('a.scoreGei IS NOT NULL')
                ->setParameter('d', $debut)
                ->setParameter('f', $fin)
                ->setParameter('m', $marches)
                ->getQuery()->getSingleScalarResult();

            return ['value' => round((float)($avg ?? 0), 1)];
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // F.2 — COURBE TEMPORELLE (SQL natif — DATE() interdit en DQL)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Alertes soumises vs qualifiées par jour — SQL natif DBAL.
     * DATE() est une fonction SQL MySQL, pas une fonction DQL Doctrine.
     */
    public function getCourbeSoumisesVsQualifiees(\DateTime $debut, \DateTime $fin, array $marches): array
    {
        $key = 'kpi_courbe_' . md5($debut->format('Ymd') . $fin->format('Ymd') . implode(',', $marches));

        return $this->cache->get($key, function (ItemInterface $item) use ($debut, $fin, $marches): array {
            $item->expiresAfter(self::CACHE_TTL);

            $conn       = $this->em->getConnection();
            $marchesStr = empty($marches) ? '' : ('AND a.market_id IN (' . implode(',', array_map('intval', $marches)) . ')');

            // Soumises par jour — SQL natif
            $soumisesRows = $conn->fetchAllAssociative(
                "SELECT DATE(a.date_creation) AS jour, COUNT(a.id) AS nb
                 FROM alert a
                 WHERE a.date_creation BETWEEN :debut AND :fin
                 {$marchesStr}
                 GROUP BY DATE(a.date_creation)
                 ORDER BY DATE(a.date_creation) ASC",
                ['debut' => $debut->format('Y-m-d 00:00:00'), 'fin' => $fin->format('Y-m-d 23:59:59')]
            );

            // Qualifiées par jour (statuts actifs) — SQL natif
            $qualifieesRows = $conn->fetchAllAssociative(
                "SELECT DATE(a.updated_at) AS jour, COUNT(a.id) AS nb
                 FROM alert a
                 WHERE a.statut NOT IN ('brouillon', 'soumis')
                 AND a.updated_at BETWEEN :debut AND :fin
                 {$marchesStr}
                 GROUP BY DATE(a.updated_at)
                 ORDER BY DATE(a.updated_at) ASC",
                ['debut' => $debut->format('Y-m-d 00:00:00'), 'fin' => $fin->format('Y-m-d 23:59:59')]
            );

            return [
                'soumises'   => $soumisesRows,
                'qualifiees' => $qualifieesRows,
            ];
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // F.3 — RÉPARTITION PAR PRIORITÉ
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Comptage par niveau de priorité — GROUP BY sur colonne scalaire : DQL OK.
     */
    public function getRepartitionPriorite(array $marches): array
    {
        $key = 'kpi_repartition_priorite_' . md5(implode(',', $marches));

        return $this->cache->get($key, function (ItemInterface $item) use ($marches): array {
            $item->expiresAfter(self::CACHE_TTL);

            $rows = $this->em->createQueryBuilder()
                ->select('a.niveauPriorite AS priorite, COUNT(a.id) AS nb')
                ->from(Alert::class, 'a')
                ->where('a.statut NOT IN (:exclues)')
                ->andWhere('a.market IN (:m)')
                ->groupBy('a.niveauPriorite')
                ->setParameter('exclues', ['clos', 'archive'])
                ->setParameter('m', $marches)
                ->getQuery()->getResult();

            // Normaliser les valeurs Enum en string
            return array_map(function (array $row): array {
                $p = $row['priorite'];
                return [
                    'priorite' => $p instanceof \App\Enum\NiveauPriorite ? $p->value : (string)$p,
                    'nb'       => (int)$row['nb'],
                ];
            }, $rows);
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // F.4 — ALERTES RÉCENTES
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Alertes récentes — on charge les entités complètes pour éviter les
     * problèmes de sérialisation des Enum en sélection scalaire.
     */
    public function getAlerteesRecentes(int $limit = 8, array $marches = []): array
    {
        $key = 'kpi_alertes_recentes_' . $limit . '_' . md5(implode(',', $marches));

        return $this->cache->get($key, function (ItemInterface $item) use ($limit, $marches): array {
            $item->expiresAfter(self::CACHE_TTL);

            $qb = $this->em->createQueryBuilder()
                ->select('a')
                ->from(Alert::class, 'a')
                ->leftJoin('a.emetteur', 'u')
                ->addSelect('u')
                ->orderBy('a.updatedAt', 'DESC')
                ->setMaxResults($limit);

            if (!empty($marches)) {
                $qb->where('a.market IN (:m)')->setParameter('m', $marches);
            }

            return $qb->getQuery()->getResult();
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // F.5 — CAS URGENCE 72H
    // ─────────────────────────────────────────────────────────────────────────

    public function getCasUrgence72hActifs(array $marches = []): array
    {
        $key = 'kpi_cas_urgence_72h_' . md5(implode(',', $marches));

        return $this->cache->get($key, function (ItemInterface $item) use ($marches): array {
            $item->expiresAfter(self::CACHE_TTL);

            $qb = $this->em->createQueryBuilder()
                ->select('c.id, a.codeGei, c.dateActivation')
                ->from(Urgence72hCase::class, 'c')
                ->join('c.alert', 'a')
                ->where('c.statutCase = :actif')
                ->setParameter('actif', 'active');

            if (!empty($marches)) {
                $qb->andWhere('a.market IN (:m)')->setParameter('m', $marches);
            }

            $resultats = $qb->orderBy('c.dateActivation', 'DESC')->getQuery()->getResult();

            $now = new \DateTime();
            foreach ($resultats as &$cas) {
                $diff              = $cas['dateActivation']->diff($now);
                $heures            = $diff->days * 24 + $diff->h;
                $cas['heuresEcoulees'] = $heures;
                $cas['heuresCibles']   = 72;
                $cas['pourcentage']    = min((int)round($heures / 72 * 100), 100);
                $cas['enRetard']       = $heures > 72;
            }

            return $resultats;
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // F.6 — ACTIVITÉ RÉCENTE
    // ─────────────────────────────────────────────────────────────────────────

    public function getActiviteRecente(int $limit = 10): array
    {
        $key = 'kpi_activite_recente_' . $limit;

        return $this->cache->get($key, function (ItemInterface $item) use ($limit): array {
            $item->expiresAfter(self::CACHE_TTL);

            // SQL natif — AccessLog peut ne pas avoir toutes les jointures Doctrine
            try {
                return $this->em->getConnection()->fetchAllAssociative(
                    "SELECT al.id, al.action, u.nom AS user_name, a.code_gei, al.date_action
                     FROM access_log al
                     LEFT JOIN user u ON u.id = al.user_id
                     LEFT JOIN alert a ON a.id = al.alert_id
                     ORDER BY al.date_action DESC
                     LIMIT :limit",
                    ['limit' => $limit]
                );
            } catch (\Exception) {
                // Table access_log peut ne pas exister en dev
                return [];
            }
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS PRIVÉS
    // ─────────────────────────────────────────────────────────────────────────

    private function countAlertesParPeriode(\DateTime $debut, \DateTime $fin, array $marches): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(a.id)')
            ->from(Alert::class, 'a')
            ->where('a.dateCreation BETWEEN :d AND :f')
            ->andWhere('a.market IN (:m)')
            ->setParameter('d', $debut)
            ->setParameter('f', $fin)
            ->setParameter('m', $marches)
            ->getQuery()->getSingleScalarResult();
    }

    private function countAlertesTraiteesParPeriode(\DateTime $debut, \DateTime $fin, array $marches): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(a.id)')
            ->from(Alert::class, 'a')
            ->where('a.statut IN (:statuts)')
            ->andWhere('a.updatedAt BETWEEN :d AND :f')
            ->andWhere('a.market IN (:m)')
                ->setParameter('statuts', ['validee', 'archive', 'clos'])
            ->setParameter('d', $debut)
            ->setParameter('f', $fin)
            ->setParameter('m', $marches)
            ->getQuery()->getSingleScalarResult();
    }
}
