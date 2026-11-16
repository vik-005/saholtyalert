<?php

namespace App\Service\Geo;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Service central d'agrégation géospatiale GEI.
 *
 * Toutes les requêtes passent par ce service, qui :
 *   - construit les clauses SQL de filtre partagées
 *   - gère le cache (TTL 60 s)
 *   - ne retourne jamais plus de données que nécessaire
 *
 * Aucune alerte complète n'est chargée en mémoire PHP :
 * seules des agrégations SQL sont renvoyées au navigateur.
 */
final class GeoAggregationService
{
    public const CACHE_TTL = 60;

    public function __construct(
        private readonly Connection    $conn,
        private readonly CacheInterface $cache,
    ) {}

    // ── Comptages par territoire ──────────────────────────────────────────

    /**
     * Comptage des alertes par marché (pays) avec métadonnées géographiques.
     *
     * @return array<int, array{
     *   market_id: int, nom: string, code_iso3: string, continent: string,
     *   region: string, latitude: float, longitude: float,
     *   total: int, critique: int, eleve: int, modere: int, faible: int,
     *   score_moyen: float|null, transmis: int
     * }>
     */
    public function countByMarket(array $filters = []): array
    {
        $key = 'geo_market_' . $this->filterKey($filters);

        return $this->cache->get($key, function (ItemInterface $item) use ($filters): array {
            $item->expiresAfter(self::CACHE_TTL);

            [$where, $params] = $this->buildAlertWhere($filters, 'a');

            return $this->conn->fetchAllAssociative(
                "SELECT
                    m.id                                                                     AS market_id,
                    m.nom,
                    m.code_iso3,
                    m.continent,
                    m.region,
                    COALESCE(NULLIF(z.latitude,  0), 8.0)                                   AS latitude,
                    COALESCE(NULLIF(z.longitude, 0), 2.0)                                   AS longitude,
                    COUNT(a.id)                                                              AS total,
                    SUM(COALESCE(a.niveau_priorite_surcharge,a.niveau_priorite)='critique') AS critique,
                    SUM(COALESCE(a.niveau_priorite_surcharge,a.niveau_priorite)='eleve')    AS eleve,
                    SUM(COALESCE(a.niveau_priorite_surcharge,a.niveau_priorite)='modere')   AS modere,
                    SUM(COALESCE(a.niveau_priorite_surcharge,a.niveau_priorite)='faible')   AS faible,
                    ROUND(AVG(COALESCE(a.score_surcharge,a.score_gei)),1)                   AS score_moyen,
                    SUM(a.transmission='oui')                                                AS transmis
                 FROM market m
                 LEFT JOIN (
                    SELECT market_id,
                           AVG(NULLIF(latitude, 0)) AS latitude,
                           AVG(NULLIF(longitude, 0)) AS longitude
                    FROM zone_geo
                    GROUP BY market_id
                 ) z ON z.market_id = m.id
                 LEFT JOIN alert   a ON a.market_id = m.id AND a.deleted_at IS NULL {$where}
                 WHERE m.actif = 1
                 GROUP BY m.id, m.nom, m.code_iso3, m.continent, m.region
                 ORDER BY total DESC",
                $params
            );
        });
    }

    /**
     * Comptage des alertes par continent.
     */
    public function countByContinent(array $filters = []): array
    {
        $key = 'geo_continent_' . $this->filterKey($filters);

        return $this->cache->get($key, function (ItemInterface $item) use ($filters): array {
            $item->expiresAfter(self::CACHE_TTL);

            [$where, $params] = $this->buildAlertWhere($filters, 'a');

            return $this->conn->fetchAllAssociative(
                "SELECT
                    m.continent,
                    COUNT(DISTINCT m.id)                                                     AS nb_pays,
                    COUNT(a.id)                                                              AS total,
                    SUM(COALESCE(a.niveau_priorite_surcharge,a.niveau_priorite)='critique') AS critique,
                    SUM(COALESCE(a.niveau_priorite_surcharge,a.niveau_priorite)='eleve')    AS eleve,
                    ROUND(AVG(COALESCE(a.score_surcharge,a.score_gei)),1)                   AS score_moyen
                 FROM market m
                 LEFT JOIN alert a ON a.market_id = m.id AND a.deleted_at IS NULL {$where}
                 WHERE m.actif = 1
                 GROUP BY m.continent
                 ORDER BY total DESC",
                $params
            );
        });
    }

    /**
     * Alertes géolocalisées avec coordonnées précises (localite_lat/lon).
     * Utilisé pour la heatmap et la carte mondiale.
     * Ne charge PAS le texte des alertes.
     */
    public function getGeolocatedAlerts(array $filters = [], int $limit = 2000): array
    {
        $key = 'geo_points_' . $this->filterKey($filters) . '_' . $limit;

        return $this->cache->get($key, function (ItemInterface $item) use ($filters, $limit): array {
            $item->expiresAfter(self::CACHE_TTL);

            [$where, $params] = $this->buildAlertWhere($filters, 'a', true);
            $params['_limit'] = $limit;

            return $this->conn->fetchAllAssociative(
                "SELECT
                    a.id,
                    a.localite_latitude                                                      AS latitude,
                    a.localite_longitude                                                     AS longitude,
                    COALESCE(a.score_surcharge, a.score_gei)                                AS score,
                    COALESCE(a.niveau_priorite_surcharge, a.niveau_priorite)                AS niveau_priorite,
                    a.categorie,
                    a.statut,
                    a.type_localisation,
                    a.port_corridor,
                    a.localite,
                    m.code_iso3                                                              AS market_iso3,
                    m.nom                                                                    AS market_nom,
                    DATE_FORMAT(a.date_creation,'%d/%m/%Y')                                 AS date_creation
                 FROM alert a
                 JOIN market m ON m.id = a.market_id
                 WHERE a.deleted_at IS NULL
                   AND a.localite_latitude  IS NOT NULL
                   AND a.localite_longitude IS NOT NULL
                   AND a.localite_latitude  BETWEEN -90  AND 90
                   AND a.localite_longitude BETWEEN -180 AND 180
                   {$where}
                 ORDER BY COALESCE(a.score_surcharge,a.score_gei) DESC
                 LIMIT :_limit",
                $params,
                ['_limit' => ParameterType::INTEGER]
            );
        });
    }

    /**
     * Centroïdes des marchés pour les alertes sans coordonnées précises.
     */
    public function getMarketCentroidAlerts(array $filters = []): array
    {
        $key = 'geo_centroids_' . $this->filterKey($filters);

        return $this->cache->get($key, function (ItemInterface $item) use ($filters): array {
            $item->expiresAfter(self::CACHE_TTL);

            [$where, $params] = $this->buildAlertWhere($filters, 'a');

            return $this->conn->fetchAllAssociative(
                "SELECT
                    m.code_iso3,
                    m.nom                                                                    AS market_nom,
                    z.latitude                                                              AS latitude,
                    z.longitude                                                             AS longitude,
                    COUNT(a.id)                                                              AS total,
                    ROUND(AVG(COALESCE(a.score_surcharge,a.score_gei)),1)                   AS score_moyen,
                    SUM(COALESCE(a.niveau_priorite_surcharge,a.niveau_priorite)='critique') AS critique
                 FROM market m
                 JOIN (
                    SELECT market_id,
                           AVG(NULLIF(latitude, 0)) AS latitude,
                           AVG(NULLIF(longitude, 0)) AS longitude
                    FROM zone_geo
                    WHERE latitude IS NOT NULL AND latitude != 0
                      AND longitude IS NOT NULL AND longitude != 0
                    GROUP BY market_id
                 ) z ON z.market_id = m.id
                 JOIN alert    a ON a.market_id = m.id AND a.deleted_at IS NULL {$where}
                 WHERE m.actif = 1
                   AND (a.localite_latitude IS NULL OR a.localite_latitude = 0
                        OR a.localite_longitude IS NULL OR a.localite_longitude = 0
                        OR a.localite_latitude NOT BETWEEN -90 AND 90
                        OR a.localite_longitude NOT BETWEEN -180 AND 180)
                 GROUP BY m.id, m.code_iso3, m.nom
                 HAVING latitude IS NOT NULL AND longitude IS NOT NULL
                 ORDER BY total DESC",
                $params
            );
        });
    }

    /**
     * Données de flux entre marchés (alertes multi-marchés).
     */
    public function getFlowData(array $filters = []): array
    {
        $key = 'geo_flows_' . $this->filterKey($filters);

        return $this->cache->get($key, function (ItemInterface $item) use ($filters): array {
            $item->expiresAfter(self::CACHE_TTL);

            [$where, $params] = $this->buildAlertWhere($filters, 'a');

            return $this->conn->fetchAllAssociative(
                "SELECT
                    a.id                                                                     AS alert_id,
                    a.code_gei,
                    COALESCE(a.score_surcharge, a.score_gei)                                AS score,
                    COALESCE(a.niveau_priorite_surcharge, a.niveau_priorite)                AS niveau_priorite,
                    a.categorie,
                    a.statut,
                    a.type_localisation,
                    a.port_corridor,
                    a.parcours_countries,
                    msrc.id                                                                  AS src_market_id,
                    msrc.nom                                                                 AS src_nom,
                    msrc.code_iso3                                                           AS src_iso3,
                    COALESCE(NULLIF(AVG(NULLIF(zsrc.latitude,0)),0),  8.0)                 AS src_lat,
                    COALESCE(NULLIF(AVG(NULLIF(zsrc.longitude,0)),0), 2.0)                 AS src_lng,
                    mads.id                                                                  AS dst_market_id,
                    mads.nom                                                                 AS dst_nom,
                    mads.code_iso3                                                           AS dst_iso3,
                    COALESCE(NULLIF(AVG(NULLIF(zdst.latitude,0)),0),  8.0)                 AS dst_lat,
                    COALESCE(NULLIF(AVG(NULLIF(zdst.longitude,0)),0), 2.0)                 AS dst_lng,
                    am.ordre
                 FROM alert a
                 JOIN market msrc    ON msrc.id = a.market_id
                 LEFT JOIN zone_geo zsrc ON zsrc.market_id = msrc.id
                 JOIN alert_market am   ON am.alert_id = a.id AND am.role = 'associe'
                 JOIN market mads    ON mads.id = am.market_id
                 LEFT JOIN zone_geo zdst ON zdst.market_id = mads.id
                 WHERE a.deleted_at IS NULL {$where}
                 GROUP BY a.id, a.code_gei, a.score_surcharge, a.score_gei,
                          a.niveau_priorite_surcharge, a.niveau_priorite,
                          a.categorie, a.statut, a.type_localisation, a.port_corridor, a.parcours_countries,
                          msrc.id, msrc.nom, msrc.code_iso3,
                          mads.id, mads.nom, mads.code_iso3, am.ordre
                 ORDER BY a.id, am.ordre",
                $params
            );
        });
    }

    /**
     * KPIs globaux pour le tableau de bord géospatial.
     */
    public function getGlobalKpis(array $filters = []): array
    {
        $key = 'geo_kpis_' . $this->filterKey($filters);

        return $this->cache->get($key, function (ItemInterface $item) use ($filters): array {
            $item->expiresAfter(self::CACHE_TTL);

            [$where, $params] = $this->buildAlertWhere($filters, 'a');

            $row = $this->conn->fetchAssociative(
                "SELECT
                    COUNT(a.id)                                                              AS total,
                    COUNT(DISTINCT a.market_id)                                              AS nb_pays,
                    SUM(COALESCE(a.niveau_priorite_surcharge,a.niveau_priorite)='critique') AS critique,
                    SUM(COALESCE(a.niveau_priorite_surcharge,a.niveau_priorite)='eleve')    AS eleve,
                    SUM(a.statut IN ('nouveau','en_cours','a_valider_saholty','ouvert','ouvert_prioritaire')) AS en_cours,
                    SUM(a.statut='validee')                                                  AS validees,
                    SUM(a.transmission='oui')                                                AS transmis,
                    SUM(a.statut IN ('archive','clos'))                                      AS closes,
                    SUM(a.localite_latitude IS NOT NULL AND a.localite_latitude != 0)        AS avec_coords,
                    ROUND(AVG(COALESCE(a.score_surcharge,a.score_gei)),1)                   AS score_moyen
                 FROM alert a
                 WHERE a.deleted_at IS NULL {$where}",
                $params
            );

            return $row ?: [
                'total'=>0,'nb_pays'=>0,'critique'=>0,'eleve'=>0,
                'en_cours'=>0,'validees'=>0,'transmis'=>0,'closes'=>0,
                'avec_coords'=>0,'score_moyen'=>null,
            ];
        });
    }

    /**
     * Répartition temporelle par mois.
     */
    public function getTimelineByMonth(array $filters = [], int $months = 12): array
    {
        $key = 'geo_timeline_' . $this->filterKey($filters) . '_' . $months;

        return $this->cache->get($key, function (ItemInterface $item) use ($filters, $months): array {
            $item->expiresAfter(self::CACHE_TTL);

            [$where, $params] = $this->buildAlertWhere($filters, 'a');
            $params['_since'] = (new \DateTimeImmutable("-{$months} months"))->format('Y-m-d');

            return $this->conn->fetchAllAssociative(
                "SELECT
                    DATE_FORMAT(a.date_creation,'%Y-%m')                                    AS mois,
                    COUNT(a.id)                                                              AS total,
                    SUM(COALESCE(a.niveau_priorite_surcharge,a.niveau_priorite)='critique') AS critique,
                    SUM(a.transmission='oui')                                                AS transmis
                 FROM alert a
                 WHERE a.deleted_at IS NULL
                   AND a.date_creation >= :_since
                   {$where}
                 GROUP BY mois
                 ORDER BY mois ASC",
                $params
            );
        });
    }

    /**
     * Top corridors et types de localisation.
     */
    public function getTopCorridors(array $filters = [], int $limit = 20): array
    {
        $key = 'geo_corridors_' . $this->filterKey($filters) . '_' . $limit;

        return $this->cache->get($key, function (ItemInterface $item) use ($filters, $limit): array {
            $item->expiresAfter(self::CACHE_TTL);

            [$where, $params] = $this->buildAlertWhere($filters, 'a');
            $params['_limit'] = $limit;

            return $this->conn->fetchAllAssociative(
                "SELECT
                    a.port_corridor                                                          AS corridor,
                    a.type_localisation,
                    COUNT(a.id)                                                              AS total,
                    SUM(COALESCE(a.niveau_priorite_surcharge,a.niveau_priorite)='critique') AS critique
                 FROM alert a
                 WHERE a.deleted_at IS NULL
                   AND a.port_corridor IS NOT NULL
                   AND TRIM(a.port_corridor) != ''
                   {$where}
                 GROUP BY a.port_corridor, a.type_localisation
                 ORDER BY total DESC
                 LIMIT :_limit",
                $params,
                ['_limit' => ParameterType::INTEGER]
            );
        });
    }

    /**
     * Répartition par catégorie.
     */
    public function getByCategorie(array $filters = []): array
    {
        $key = 'geo_cats_' . $this->filterKey($filters);

        return $this->cache->get($key, function (ItemInterface $item) use ($filters): array {
            $item->expiresAfter(self::CACHE_TTL);

            [$where, $params] = $this->buildAlertWhere($filters, 'a');

            return $this->conn->fetchAllAssociative(
                "SELECT
                    COALESCE(a.categorie,'Non renseignée') AS categorie,
                    COUNT(a.id)                            AS total
                 FROM alert a
                 WHERE a.deleted_at IS NULL {$where}
                 GROUP BY a.categorie
                 ORDER BY total DESC
                 LIMIT 15",
                $params
            );
        });
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * Construit la clause WHERE et les paramètres à partir des filtres.
     * $requireCoords = true pour la heatmap (coordonnées précises uniquement).
     *
     * @return array{0: string, 1: array<string,mixed>}
     */
    public function buildAlertWhere(array $filters, string $alias = 'a', bool $requireCoords = false): array
    {
        $clauses = [];
        $params  = [];

        if (!empty($filters['statut'])) {
            $clauses[] = "{$alias}.statut = :statut";
            $params['statut'] = $filters['statut'];
        }
        if (!empty($filters['categorie'])) {
            $clauses[] = "{$alias}.categorie = :categorie";
            $params['categorie'] = $filters['categorie'];
        }
        if (!empty($filters['priorite'])) {
            $clauses[] = "COALESCE({$alias}.niveau_priorite_surcharge,{$alias}.niveau_priorite) = :priorite";
            $params['priorite'] = $filters['priorite'];
        }
        if (!empty($filters['typeAlerte'])) {
            $clauses[] = "{$alias}.type_alerte = :typeAlerte";
            $params['typeAlerte'] = $filters['typeAlerte'];
        }
        if (!empty($filters['typeLocalisation'])) {
            $clauses[] = "{$alias}.type_localisation = :typeLocalisation";
            $params['typeLocalisation'] = $filters['typeLocalisation'];
        }
        if (!empty($filters['scoreMin'])) {
            $clauses[] = "COALESCE({$alias}.score_surcharge,{$alias}.score_gei) >= :scoreMin";
            $params['scoreMin'] = (int) $filters['scoreMin'];
        }
        if (!empty($filters['scoreMax'])) {
            $clauses[] = "COALESCE({$alias}.score_surcharge,{$alias}.score_gei) <= :scoreMax";
            $params['scoreMax'] = (int) $filters['scoreMax'];
        }
        if (!empty($filters['dateDebut'])) {
            $clauses[] = "{$alias}.date_creation >= :dateDebut";
            $params['dateDebut'] = $filters['dateDebut'] . ' 00:00:00';
        }
        if (!empty($filters['dateFin'])) {
            $clauses[] = "{$alias}.date_creation <= :dateFin";
            $params['dateFin'] = $filters['dateFin'] . ' 23:59:59';
        }
        if (!empty($filters['markets']) && is_array($filters['markets'])) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $filters['markets']))));
            if ($ids !== []) {
                $placeholders = [];
                foreach ($ids as $i => $id) {
                    $k = '_mkt' . $i;
                    $placeholders[] = ':' . $k;
                    $params[$k] = $id;
                }
                $clauses[] = "{$alias}.market_id IN (" . implode(',', $placeholders) . ")";
            }
        }
        if (!empty($filters['continent'])) {
            $clauses[] = "{$alias}.market_id IN (SELECT id FROM market WHERE continent = :continent AND actif = 1)";
            $params['continent'] = $filters['continent'];
        }
        if ($requireCoords) {
            $clauses[] = "{$alias}.localite_latitude  IS NOT NULL AND {$alias}.localite_latitude  != 0";
            $clauses[] = "{$alias}.localite_longitude IS NOT NULL AND {$alias}.localite_longitude != 0";
        }

        $where = $clauses !== [] ? 'AND ' . implode(' AND ', $clauses) : '';
        return [$where, $params];
    }

    /**
     * Clé de cache déterministe.
     */
    private function filterKey(array $filters): string
    {
        ksort($filters);
        return substr(md5(json_encode($filters, JSON_THROW_ON_ERROR)), 0, 12);
    }
}
