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
                z.id,
                z.latitude,
                z.longitude,
                z.nom,
                z.polygon_wkt AS polygonWkt,
                m.id AS marketId,
                m.code_iso3 AS codeIso3,
                COUNT(a.id) AS alertCount,
                SUBSTRING_INDEX(
                    GROUP_CONCAT(COALESCE(a.niveau_priorite_surcharge, a.niveau_priorite) ORDER BY FIELD(COALESCE(a.niveau_priorite_surcharge, a.niveau_priorite), 'critique', 'eleve', 'modere', 'faible') ASC),
                    ',', 1
                ) AS maxPriority
            FROM zone_geo z
            INNER JOIN market m ON z.market_id = m.id
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
