<?php

namespace App\Repository;

use App\Entity\ZoneGeo;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ZoneGeo>
 */
class ZoneGeoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ZoneGeo::class);
    }

    /**
     * Récupère toutes les zones avec le nombre d'alertes actives par zone.
     * Optimisé avec cache : invalider à chaque modification d'alerte.
     */
    /**
     * Récupère toutes les zones avec comptage d'alertes filtré.
     * Retourne aussi le codeIso3 et l'id du marché pour enrichissement.
     */
    public function findWithAlertCountFiltered(array $filters = []): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $params = [];
        $joinClauses = [];

        if (!empty($filters['statut'])) {
            $joinClauses[] = 'a.statut = :statut';
            $params['statut'] = $filters['statut'];
        }

        if (!empty($filters['categorie'])) {
            $joinClauses[] = 'a.categorie = :categorie';
            $params['categorie'] = $filters['categorie'];
        }

        if (!empty($filters['priorite'])) {
            $joinClauses[] = 'COALESCE(a.niveau_priorite_surcharge, a.niveau_priorite) = :priorite';
            $params['priorite'] = $filters['priorite'];
        }

        if (!empty($filters['urgence'])) {
            $joinClauses[] = 'a.urgence = :urgence';
            $params['urgence'] = $filters['urgence'];
        }

        if (!empty($filters['typeAlerte'])) {
            $joinClauses[] = 'a.type_alerte = :typeAlerte';
            $params['typeAlerte'] = $filters['typeAlerte'];
        }

        if (!empty($filters['scoreMin'])) {
            $joinClauses[] = 'COALESCE(a.score_surcharge, a.score_gei) >= :scoreMin';
            $params['scoreMin'] = (int) $filters['scoreMin'];
        }

        if (!empty($filters['scoreMax'])) {
            $joinClauses[] = 'COALESCE(a.score_surcharge, a.score_gei) <= :scoreMax';
            $params['scoreMax'] = (int) $filters['scoreMax'];
        }

        if (!empty($filters['origine'])) {
            $joinClauses[] = 'a.origine = :origine';
            $params['origine'] = $filters['origine'];
        }

        if (!empty($filters['dateDebut'])) {
            $joinClauses[] = 'a.date_creation >= :debut';
            $params['debut'] = $filters['dateDebut'] . ' 00:00:00';
        }

        if (!empty($filters['dateFin'])) {
            $joinClauses[] = 'a.date_creation <= :fin';
            $params['fin'] = $filters['dateFin'] . ' 23:59:59';
        }

        $joinAlertSql = 'LEFT JOIN alert a ON a.market_id = m.id AND a.deleted_at IS NULL';
        if (!empty($joinClauses)) {
            $joinAlertSql .= ' AND ' . implode(' AND ', $joinClauses);
        }

        $whereClauses = ['m.actif = 1'];

        if (!empty($filters['allowedMarkets'])) {
            $allowedIds = array_values(array_filter(array_map('intval', $filters['allowedMarkets'])));
            if ($allowedIds) {
                $placeholders = [];
                foreach ($allowedIds as $i => $id) {
                    $key = 'allowed_market_' . $i;
                    $params[$key] = $id;
                    $placeholders[] = ':' . $key;
                }
                $whereClauses[] = 'm.id IN (' . implode(', ', $placeholders) . ')';
            }
        }

        $marketFilter = $filters['markets'] ?? $filters['marche'] ?? [];
        if (!empty($marketFilter) && is_array($marketFilter)) {
            $marketIds = array_values(array_filter(array_map('intval', $marketFilter)));
            if (!empty($marketIds)) {
                $placeholders = [];
                foreach ($marketIds as $i => $id) {
                    $key = 'market_' . $i;
                    $params[$key] = $id;
                    $placeholders[] = ':' . $key;
                }
                $whereClauses[] = 'm.id IN (' . implode(', ', $placeholders) . ')';
            }
        }

        $whereSql = 'WHERE ' . implode(' AND ', $whereClauses);

        $sql = "
            SELECT
                COALESCE(z.id, m.id) AS id,
                COALESCE(z.latitude, CASE m.code_iso3
                    WHEN 'BEN' THEN 9.3077 WHEN 'TGO' THEN 8.6195
                    WHEN 'GHA' THEN 7.9465 WHEN 'BFA' THEN 12.2383
                    WHEN 'MLI' THEN 17.5707 WHEN 'NER' THEN 17.6078
                    WHEN 'SEN' THEN 14.4974 WHEN 'CIV' THEN 7.5400
                    WHEN 'GIN' THEN 9.9456 WHEN 'NGA' THEN 9.0820
                    ELSE 8.0000 END) AS latitude,
                COALESCE(z.longitude, CASE m.code_iso3
                    WHEN 'BEN' THEN 2.3158 WHEN 'TGO' THEN 0.8248
                    WHEN 'GHA' THEN -1.0232 WHEN 'BFA' THEN -1.5616
                    WHEN 'MLI' THEN -3.9962 WHEN 'NER' THEN 8.0817
                    WHEN 'SEN' THEN -14.4524 WHEN 'CIV' THEN -5.5471
                    WHEN 'GIN' THEN -9.6966 WHEN 'NGA' THEN 8.6753
                    ELSE 2.0000 END) AS longitude,
                COALESCE(z.nom, m.nom) AS nom,
                z.polygon_wkt AS polygonWkt,
                m.id AS marketId,
                m.code_iso3 AS codeIso3,
                COUNT(a.id) AS alertCount,
                SUBSTRING_INDEX(
                    GROUP_CONCAT(COALESCE(a.niveau_priorite_surcharge, a.niveau_priorite) ORDER BY FIELD(COALESCE(a.niveau_priorite_surcharge, a.niveau_priorite), 'critique', 'eleve', 'modere', 'faible') ASC),
                    ',', 1
                ) AS maxPriority
            FROM market m
            LEFT JOIN zone_geo z ON z.market_id = m.id
            {$joinAlertSql}
            {$whereSql}
            GROUP BY z.id, m.id, m.code_iso3
            ORDER BY alertCount DESC, z.nom ASC
        ";

        $stmt = $conn->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        return $stmt->executeQuery()->fetchAllAssociative();
    }

    /**
     * @deprecated Remplacé par findWithAlertCountFiltered
     */
    public function findWithAlertCount(array $filters = []): array
    {
        return $this->findWithAlertCountFiltered($filters);
    }
}
