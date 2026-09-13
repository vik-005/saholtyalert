<?php

namespace App\Service;

use App\Dto\CorridorFilterDTO;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Service de statistiques GEI — tous les graphiques de la page /statistiques.
 *
 * Bibliothèque JS utilisée : Chart.js 4 (déjà présente dans base.html.twig).
 * Toutes les requêtes sont SQL natif DBAL (pas DQL) pour supporter DATE(), GROUP_CONCAT,
 * CASE WHEN et les pivots nécessaires aux graphiques empilés.
 *
 * Cache : TTL 300s (5 min), invalidé après import ou écriture.
 */
class StatistiquesService
{
    private const TTL = 300;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CacheInterface         $cache,
    ) {}

    private function conn(): Connection
    {
        return $this->em->getConnection();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // A.1 — STATISTIQUES PAR PAYS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Volume d'alertes par pays — bâtons verticaux triés décroissant.
     * Retourne [['pays' => 'Bénin', 'iso3' => 'BEN', 'nb' => 12], ...]
     * Si $typeLocalisation est fourni, filtre sur ce type (port, corridor, aeroport).
     * Si null ou chaîne vide, ne filtre pas par type (tous les types).
     */
    public function getVolumeParPays(string $debut, string $fin, array $marketIds = [], ?string $typeLocalisation = null): array
    {
        $key = 'stat_volume_pays_' . md5($debut . $fin . implode(',', $marketIds) . ($typeLocalisation ?? ''));
        return $this->cache->get($key, function (ItemInterface $item) use ($debut, $fin, $marketIds, $typeLocalisation) {
            $item->expiresAfter(self::TTL);
            $where = $this->whereClause($marketIds);
            $typeWhere = '';
            $params = ['debut' => $debut . ' 00:00:00', 'fin' => $fin . ' 23:59:59'];

            if ($typeLocalisation) {
                $typeWhere = 'AND a.type_localisation = :typeLoc';
                $params['typeLoc'] = $typeLocalisation;
            }

            return $this->conn()->fetchAllAssociative(
                "SELECT m.nom AS pays, m.code_iso3 AS iso3, COUNT(a.id) AS nb
                 FROM alert a
                 JOIN market m ON m.id = a.market_id
                 WHERE a.date_creation BETWEEN :debut AND :fin {$where} {$typeWhere}
                 GROUP BY m.id, m.nom, m.code_iso3
                 ORDER BY nb DESC",
                $params
            );
        });
    }

    /**
     * Score moyen par pays — bâtons horizontaux triés décroissant.
     * Retourne [['pays' => 'Bénin', 'score_moyen' => 17.3], ...]
     */
    public function getScoreMoyenParPays(string $debut, string $fin, array $marketIds = []): array
    {
        $key = 'stat_score_pays_' . md5($debut . $fin . implode(',', $marketIds));
        return $this->cache->get($key, function (ItemInterface $item) use ($debut, $fin, $marketIds) {
            $item->expiresAfter(self::TTL);
            $where = $this->whereClause($marketIds);
            return $this->conn()->fetchAllAssociative(
                "SELECT m.nom AS pays, m.code_iso3 AS iso3,
                        ROUND(AVG(a.score_gei), 1) AS score_moyen,
                        COUNT(a.id) AS nb
                 FROM alert a
                 JOIN market m ON m.id = a.market_id
                 WHERE a.score_gei IS NOT NULL
                   AND a.date_creation BETWEEN :debut AND :fin {$where}
                 GROUP BY m.id, m.nom, m.code_iso3
                 ORDER BY score_moyen DESC",
                ['debut' => $debut . ' 00:00:00', 'fin' => $fin . ' 23:59:59']
            );
        });
    }

    /**
     * Statuts empilés par pays — bâtons empilés.
     * Retourne [['pays' => 'Bénin', 'statut' => 'transmis', 'nb' => 5], ...]
     */
    public function getStatutsParPays(string $debut, string $fin, array $marketIds = []): array
    {
        $key = 'stat_statuts_pays_' . md5($debut . $fin . implode(',', $marketIds));
        return $this->cache->get($key, function (ItemInterface $item) use ($debut, $fin, $marketIds) {
            $item->expiresAfter(self::TTL);
            $where = $this->whereClause($marketIds);
            return $this->conn()->fetchAllAssociative(
                "SELECT m.nom AS pays, a.statut, COUNT(a.id) AS nb
                 FROM alert a
                 JOIN market m ON m.id = a.market_id
                 WHERE a.date_creation BETWEEN :debut AND :fin {$where}
                 GROUP BY m.id, m.nom, a.statut
                 ORDER BY m.nom, nb DESC",
                ['debut' => $debut . ' 00:00:00', 'fin' => $fin . ' 23:59:59']
            );
        });
    }

    /**
     * Taux de transmission par pays — bâtons horizontaux.
     * Retourne [['pays' => 'Bénin', 'taux' => 83.3, 'transmis' => 5, 'total' => 6], ...]
     */
    public function getTauxTransmissionParPays(string $debut, string $fin, array $marketIds = []): array
    {
        $key = 'stat_tx_pays_' . md5($debut . $fin . implode(',', $marketIds));
        return $this->cache->get($key, function (ItemInterface $item) use ($debut, $fin, $marketIds) {
            $item->expiresAfter(self::TTL);
            $where = $this->whereClause($marketIds);
            $rows = $this->conn()->fetchAllAssociative(
                "SELECT m.nom AS pays,
                        SUM(CASE WHEN a.transmission = 'oui' THEN 1 ELSE 0 END) AS transmis,
                        COUNT(a.id) AS total
                 FROM alert a
                 JOIN market m ON m.id = a.market_id
                 WHERE a.date_creation BETWEEN :debut AND :fin {$where}
                 GROUP BY m.id, m.nom
                 ORDER BY transmis DESC",
                ['debut' => $debut . ' 00:00:00', 'fin' => $fin . ' 23:59:59']
            );
            return array_map(function ($r) {
                $taux = $r['total'] > 0 ? round($r['transmis'] / $r['total'] * 100, 1) : 0;
                return array_merge($r, ['taux' => $taux]);
            }, $rows);
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // A.2 — JAUGES (GAUGE SEMI-CIRCULAIRES)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Taux de conformité SLA 72h — jauge 0-100%.
     * Retourne ['taux' => 87.5, 'respectees' => 7, 'total' => 8]
     */
    public function getTauxSla(string $debut, string $fin): array
    {
        $key = 'stat_sla_' . md5($debut . $fin);
        return $this->cache->get($key, function (ItemInterface $item) use ($debut, $fin) {
            $item->expiresAfter(60); // TTL court — SLA critique, pas de délai trop long
            $row = $this->conn()->fetchAssociative(
                "SELECT
                    SUM(CASE WHEN c.statut_case = 'cloturee' AND c.delai_reel_heures <= 72 THEN 1 ELSE 0 END) AS respectees,
                    SUM(CASE WHEN c.statut_case = 'cloturee' THEN 1 ELSE 0 END) AS cloturees,
                    COUNT(c.id) AS total
                 FROM urgence_72h_case c
                 JOIN alert a ON a.id = c.alert_id
                 WHERE c.date_activation >= :debut
                   AND c.date_activation < DATE_ADD(:fin, INTERVAL 1 DAY)
                   AND a.urgence = '72h'
                   AND a.statut = 'validee'",
                ['debut' => $debut, 'fin' => $fin]
            );
            $respectees = (int)($row['respectees'] ?? 0);
            $cloturees  = (int)($row['cloturees'] ?? 0);
            $total      = (int)($row['total'] ?? 0);
            $taux = $cloturees > 0 ? round($respectees / $cloturees * 100, 1) : null;
            return [
                'taux'        => $taux,
                'respectees'  => $respectees,
                'cloturees'   => $cloturees,
                'total'       => $total,
                'color_class' => $taux === null ? 'gauge-gray'
                    : ($taux >= 90 ? 'gauge-green' : ($taux >= 70 ? 'gauge-amber' : 'gauge-red')),
            ];
        });
    }

    /**
     * Taux d'alertes exploitables (actionnable / total) — jauge.
     */
    public function getTauxExploitables(string $debut, string $fin, array $marketIds = []): array
    {
        $key = 'stat_exploit_' . md5($debut . $fin . implode(',', $marketIds));
        return $this->cache->get($key, function (ItemInterface $item) use ($debut, $fin, $marketIds) {
            $item->expiresAfter(self::TTL);
            $where = $this->whereClause($marketIds);
            $row = $this->conn()->fetchAssociative(
                "SELECT
                    SUM(CASE WHEN a.exploitabilite = 'actionnable' THEN 1 ELSE 0 END) AS actionnables,
                    COUNT(a.id) AS total
                 FROM alert a
                 WHERE a.date_creation BETWEEN :debut AND :fin {$where}",
                ['debut' => $debut . ' 00:00:00', 'fin' => $fin . ' 23:59:59']
            );
            $actionnables = (int)($row['actionnables'] ?? 0);
            $total        = (int)($row['total'] ?? 0);
            $taux = $total > 0 ? round($actionnables / $total * 100, 1) : 0;
            return [
                'taux'        => $taux,
                'actionnables'=> $actionnables,
                'total'       => $total,
                'color_class' => $taux >= 60 ? 'gauge-green' : ($taux >= 40 ? 'gauge-amber' : 'gauge-red'),
            ];
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // A.3 — AUTRES GRAPHIQUES
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Top opérateurs / acteurs — bâtons horizontaux top 10.
     * Classement par nombre d'alertes associées à chaque opérateur.
     */
    public function getTopOperateurs(string $debut, string $fin, array $marketIds = [], int $limit = 10): array
    {
        $key = 'stat_operateurs_' . md5($debut . $fin . implode(',', $marketIds) . $limit);
        return $this->cache->get($key, function (ItemInterface $item) use ($debut, $fin, $marketIds, $limit) {
            $item->expiresAfter(self::TTL);
            $where = $this->whereClause($marketIds);
            $params = ['debut' => $debut . ' 00:00:00', 'fin' => $fin . ' 23:59:59'];

            return $this->conn()->fetchAllAssociative(
                "SELECT COALESCE(a.operateur_acteur, 'Non renseigné') AS operateurs, COUNT(a.id) AS nb
                 FROM alert a
                 WHERE a.operateur_acteur IS NOT NULL
                   AND a.operateur_acteur != ''
                   AND a.date_creation BETWEEN :debut AND :fin {$where}
                 GROUP BY a.operateur_acteur
                 ORDER BY nb DESC
                 LIMIT {$limit}",
                $params
            );
        });
    }

    /**
     * Répartition par catégorie — donut/bâtons.
     */
    public function getRepartitionCategorie(string $debut, string $fin, array $marketIds = []): array
    {
        $key = 'stat_cat_' . md5($debut . $fin . implode(',', $marketIds));
        return $this->cache->get($key, function (ItemInterface $item) use ($debut, $fin, $marketIds) {
            $item->expiresAfter(self::TTL);
            $where = $this->whereClause($marketIds);
            return $this->conn()->fetchAllAssociative(
                "SELECT COALESCE(a.categorie, 'autre') AS categorie, COUNT(a.id) AS nb
                 FROM alert a
                 WHERE a.date_creation BETWEEN :debut AND :fin {$where}
                 GROUP BY a.categorie
                 ORDER BY nb DESC",
                ['debut' => $debut . ' 00:00:00', 'fin' => $fin . ' 23:59:59']
            );
        });
    }

    /**
     * Top corridors — bâtons horizontaux top 10.
     * Optionnel : filtrer par type de localisation (port / corridor).
     */
    public function getTopCorridors(string $debut, string $fin, array $marketIds = [], int $limit = 10, ?string $typeLocalisation = null): array
    {
        $key = 'stat_corridors_' . md5($debut . $fin . implode(',', $marketIds) . $limit . ($typeLocalisation ?? ''));
        return $this->cache->get($key, function (ItemInterface $item) use ($debut, $fin, $marketIds, $limit, $typeLocalisation) {
            $item->expiresAfter(self::TTL);
            $where = $this->whereClause($marketIds);
            $typeWhere = '';
            $params = ['debut' => $debut . ' 00:00:00', 'fin' => $fin . ' 23:59:59'];

            if ($typeLocalisation) {
                $typeWhere = 'AND a.type_localisation = :typeLoc';
                $params['typeLoc'] = $typeLocalisation;
            }

            return $this->conn()->fetchAllAssociative(
                "SELECT COALESCE(a.port_corridor, 'Non renseigné') AS corridor, COUNT(a.id) AS nb
                 FROM alert a
                 WHERE a.port_corridor IS NOT NULL
                   AND a.port_corridor != ''
                   AND a.date_creation BETWEEN :debut AND :fin {$where} {$typeWhere}
                 GROUP BY a.port_corridor
                 ORDER BY nb DESC
                 LIMIT {$limit}",
                $params
            );
        });
    }

    /**
     * Répartition par type de localisation (Port vs Corridor vs Aéroport).
     * Retourne [['type' => 'port', 'nb' => 12], ['type' => 'corridor', 'nb' => 8], ['type' => 'aeroport', 'nb' => 3]]
     * Si $typeLocalisation est fourni, filtre sur ce type (utile pour cohérence).
     */
    public function getStatsByLocalisationType(string $debut, string $fin, array $marketIds = [], ?string $typeLocalisation = null): array
    {
        $key = 'stat_loc_type_' . md5($debut . $fin . implode(',', $marketIds) . ($typeLocalisation ?? ''));
        return $this->cache->get($key, function (ItemInterface $item) use ($debut, $fin, $marketIds, $typeLocalisation) {
            $item->expiresAfter(self::TTL);
            $where = $this->whereClause($marketIds);
            $typeWhere = '';
            $params = ['debut' => $debut . ' 00:00:00', 'fin' => $fin . ' 23:59:59'];

            if ($typeLocalisation) {
                $typeWhere = 'AND a.type_localisation = :typeLoc';
                $params['typeLoc'] = $typeLocalisation;
            }

            return $this->conn()->fetchAllAssociative(
                "SELECT a.type_localisation AS type, COUNT(a.id) AS nb
                 FROM alert a
                 WHERE a.type_localisation IS NOT NULL
                   AND a.date_creation BETWEEN :debut AND :fin {$where} {$typeWhere}
                 GROUP BY a.type_localisation
                 ORDER BY nb DESC",
                $params
            );
        });
    }

    /**
     * Heatmap Fiabilité × Crédibilité (matrice 4×4 Annexe C).
     * Retourne [['fiabilite' => 'A', 'credibilite' => 1, 'nb' => 5], ...]
     */
    public function getHeatmapFiabiliteCredibilite(string $debut, string $fin, array $marketIds = []): array
    {
        $key = 'stat_heatmap_' . md5($debut . $fin . implode(',', $marketIds));
        return $this->cache->get($key, function (ItemInterface $item) use ($debut, $fin, $marketIds) {
            $item->expiresAfter(self::TTL);
            $where = $this->whereClause($marketIds);
            $rows = $this->conn()->fetchAllAssociative(
                "SELECT a.fiabilite_source AS fiabilite,
                        a.credibilite_contenu AS credibilite,
                        COUNT(a.id) AS nb
                 FROM alert a
                 WHERE a.fiabilite_source IS NOT NULL
                   AND a.credibilite_contenu IS NOT NULL
                   AND a.date_creation BETWEEN :debut AND :fin {$where}
                 GROUP BY a.fiabilite_source, a.credibilite_contenu
                 ORDER BY a.fiabilite_source, a.credibilite_contenu",
                ['debut' => $debut . ' 00:00:00', 'fin' => $fin . ' 23:59:59']
            );
            // Pivot en tableau 4×4 indexé [fiabilite][credibilite] = nb
            $matrix = [];
            foreach (['A', 'B', 'C', 'D'] as $f) {
                for ($c = 1; $c <= 4; $c++) {
                    $matrix[$f][$c] = 0;
                }
            }
            foreach ($rows as $row) {
                $f = strtoupper((string)$row['fiabilite']);
                $c = (int)$row['credibilite'];
                if (isset($matrix[$f][$c])) {
                    $matrix[$f][$c] = (int)$row['nb'];
                }
            }
            return $matrix;
        });
    }

    /**
     * Activité par agent (nb soumissions) — bâtons horizontaux, visible Manager.
     */
    public function getActiviteParAgent(string $debut, string $fin, array $marketIds = []): array
    {
        $key = 'stat_agents_' . md5($debut . $fin . implode(',', $marketIds));
        return $this->cache->get($key, function (ItemInterface $item) use ($debut, $fin, $marketIds) {
            $item->expiresAfter(self::TTL);
            $where = $this->whereClause($marketIds);
            return $this->conn()->fetchAllAssociative(
                "SELECT CONCAT(u.prenom, ' ', u.nom) AS agent, COUNT(a.id) AS nb
                 FROM alert a
                 JOIN user u ON u.id = a.emetteur_id
                 WHERE a.date_creation BETWEEN :debut AND :fin {$where}
                 GROUP BY a.emetteur_id, u.prenom, u.nom
                 ORDER BY nb DESC
                 LIMIT 15",
                ['debut' => $debut . ' 00:00:00', 'fin' => $fin . ' 23:59:59']
            );
        });
    }

    /**
     * Délai moyen de traitement par pays (soumission → clôture, en heures).
     */
    public function getDelaiMoyenTraitement(string $debut, string $fin, array $marketIds = []): array
    {
        $key = 'stat_delai_' . md5($debut . $fin . implode(',', $marketIds));
        return $this->cache->get($key, function (ItemInterface $item) use ($debut, $fin, $marketIds) {
            $item->expiresAfter(self::TTL);
            $where = $this->whereClause($marketIds);
            return $this->conn()->fetchAllAssociative(
                "SELECT m.nom AS pays,
                        ROUND(AVG(TIMESTAMPDIFF(HOUR, a.date_creation, a.updated_at)), 1) AS delai_moyen_h,
                        COUNT(a.id) AS nb
                 FROM alert a
                 JOIN market m ON m.id = a.market_id
                 WHERE a.statut IN ('validee', 'archive', 'clos')
                   AND a.date_creation BETWEEN :debut AND :fin {$where}
                 GROUP BY m.id, m.nom
                 ORDER BY delai_moyen_h ASC",
                ['debut' => $debut . ' 00:00:00', 'fin' => $fin . ' 23:59:59']
            );
        });
    }

    /**
     * Évolution temporelle (déjà dans AlertRepository::getEvolutionData).
     * On expose ici un wrapper avec filtres marché + période.
     */
    public function getEvolutionTemporelle(string $debut, string $fin, array $marketIds = [], string $granularite = 'day'): array
    {
        $key = 'stat_evol_' . md5($debut . $fin . implode(',', $marketIds) . $granularite);
        return $this->cache->get($key, function (ItemInterface $item) use ($debut, $fin, $marketIds, $granularite) {
            $item->expiresAfter(self::TTL);
            $where = $this->whereClause($marketIds);
            $groupFormat = $granularite === 'week' ? '%Y-%u' : '%Y-%m-%d';
            $rows = $this->conn()->fetchAllAssociative(
                "SELECT DATE_FORMAT(a.date_creation, '{$groupFormat}') AS periode,
                        COUNT(a.id) AS soumises,
                        SUM(CASE WHEN a.transmission = 'oui' THEN 1 ELSE 0 END) AS transmises
                 FROM alert a
                 WHERE a.date_creation BETWEEN :debut AND :fin {$where}
                 GROUP BY periode
                 ORDER BY periode ASC",
                ['debut' => $debut . ' 00:00:00', 'fin' => $fin . ' 23:59:59']
            );
            return [
                'labels'    => array_column($rows, 'periode'),
                'soumises'  => array_map('intval', array_column($rows, 'soumises')),
                'transmises'=> array_map('intval', array_column($rows, 'transmises')),
            ];
        });
    }

    /**
     * Invalidation du cache stats après import ou modification.
     */
    public function invalidateAll(): void
    {
        try {
            if (method_exists($this->cache, 'invalidateTags')) {
                $this->cache->invalidateTags(['statistiques']);
            }
        } catch (\Exception) {}
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CORRIDOR DASHBOARD — méthode centralisée page /corridors
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Retourne toutes les statistiques nécessaires à la page /corridors en
     * un seul appel de service. Évite les requêtes redondantes.
     *
     * Retourne un tableau associatif avec :
     *   - top_localisations  : top éléments avec corridor, type, pays, nb, part_relative
     *   - volume_par_pays    : volume d'alertes par marché selon le type
     *   - loc_type_stats     : répartition globale corridor/port/aéroport (seulement si type = Tous)
     *   - kpis               : total, nb_corridors, nb_ports, nb_aeroports, top_element
     *   - has_data           : bool
     */
    public function getCorridorDashboard(CorridorFilterDTO $dto): array
    {
        // Si aucun marché sélectionné -> état vide immédiat (Spec A.1 & A.3)
        if (empty($dto->marketIds)) {
            return [
                'top_localisations' => [],
                'volume_par_pays'   => [],
                'loc_type_stats'    => [],
                'kpis'              => ['total' => 0, 'nb_corridors' => 0, 'nb_ports' => 0, 'nb_aeroports' => 0, 'top_element' => null],
                'has_data'          => false,
            ];
        }

        $key = 'corridor_dashboard_' . md5(
            $dto->dateDebut . $dto->dateFin .
            implode(',', $dto->marketIds) .
            ($dto->typeLoc ?? '')
        );

        return $this->cache->get($key, function (ItemInterface $item) use ($dto) {
            $item->expiresAfter(self::TTL);

            $where     = $this->whereClause($dto->marketIds);
            $params    = ['debut' => $dto->dateDebut . ' 00:00:00', 'fin' => $dto->dateFin . ' 23:59:59'];
            $typeWhere = '';

            if ($dto->typeLoc) {
                $typeWhere = 'AND a.type_localisation = :typeLoc';
                $params['typeLoc'] = $dto->typeLoc;
            }

            // ── 1. Top localisations avec part relative ───────────────────────
            // Exclusion native des entrées vides (Spec A.2 : HAVING COUNT > 0)
            $rawTop = $this->conn()->fetchAllAssociative(
                "SELECT
                    a.port_corridor                           AS corridor,
                    COALESCE(a.type_localisation, 'corridor') AS type,
                    m.nom                                     AS pays,
                    m.code_iso3                               AS iso3,
                    COUNT(a.id)                               AS nb
                 FROM alert a
                 JOIN market m ON m.id = a.market_id
                 WHERE a.port_corridor IS NOT NULL
                   AND a.port_corridor != ''
                   AND a.date_creation BETWEEN :debut AND :fin
                   {$where} {$typeWhere}
                 GROUP BY a.port_corridor, a.type_localisation, m.nom, m.code_iso3
                 HAVING COUNT(a.id) > 0
                 ORDER BY nb DESC",
                $params
            );

            // Calcul de la part relative côté PHP (évite sous-requête SQL)
            $totalTop = array_sum(array_column($rawTop, 'nb'));
            $topLocalisations = array_map(function (array $row) use ($totalTop) {
                $row['nb'] = (int) $row['nb'];
                $row['part_relative'] = $totalTop > 0
                    ? round($row['nb'] / $totalTop * 100, 1)
                    : 0.0;
                return $row;
            }, $rawTop);

            // ── 2. Volume par pays (respecte type & cohérence croisée C.6) ───
            // Filtré sur a.port_corridor valide pour garantir l'égalité stricte
            // Total volume_par_pays = Somme top_localisations (Spec C.6)
            $volumeParPays = $this->conn()->fetchAllAssociative(
                "SELECT m.nom AS pays, m.code_iso3 AS iso3, COUNT(a.id) AS nb
                 FROM alert a
                 JOIN market m ON m.id = a.market_id
                 WHERE a.port_corridor IS NOT NULL
                   AND a.port_corridor != ''
                   AND a.date_creation BETWEEN :debut AND :fin
                   {$where} {$typeWhere}
                 GROUP BY m.id, m.nom, m.code_iso3
                 HAVING COUNT(a.id) > 0
                 ORDER BY nb DESC",
                $params
            );

            // ── 3. Répartition par type (toujours sur les marchés sélectionnés) ──
            $paramsGlobal = ['debut' => $dto->dateDebut . ' 00:00:00', 'fin' => $dto->dateFin . ' 23:59:59'];
            $locTypeStats = $this->conn()->fetchAllAssociative(
                "SELECT COALESCE(a.type_localisation, 'corridor') AS type,
                        COUNT(a.id) AS nb
                 FROM alert a
                 WHERE a.port_corridor IS NOT NULL
                   AND a.port_corridor != ''
                   AND a.date_creation BETWEEN :debut AND :fin
                   {$where}
                 GROUP BY a.type_localisation
                 HAVING COUNT(a.id) > 0
                 ORDER BY nb DESC",
                $paramsGlobal
            );

            // ── 4. KPIs ──────────────────────────────────────────────────────
            $kpiRows = $this->conn()->fetchAllAssociative(
                "SELECT COALESCE(a.type_localisation, 'corridor') AS type,
                        COUNT(a.id) AS nb
                 FROM alert a
                 WHERE a.port_corridor IS NOT NULL
                   AND a.port_corridor != ''
                   AND a.date_creation BETWEEN :debut AND :fin
                   {$where} {$typeWhere}
                 GROUP BY a.type_localisation
                 HAVING COUNT(a.id) > 0",
                $params
            );

            $kpis = ['total' => 0, 'nb_corridors' => 0, 'nb_ports' => 0, 'nb_aeroports' => 0];
            foreach ($kpiRows as $row) {
                $count = (int) $row['nb'];
                $kpis['total'] += $count;
                match ($row['type']) {
                    'corridor' => $kpis['nb_corridors'] += $count,
                    'port'     => $kpis['nb_ports']     += $count,
                    'aeroport' => $kpis['nb_aeroports'] += $count,
                    default    => null,
                };
            }
            $kpis['top_element'] = !empty($topLocalisations) ? $topLocalisations[0] : null;

            return [
                'top_localisations' => $topLocalisations,
                'volume_par_pays'   => $volumeParPays,
                'loc_type_stats'    => $locTypeStats,
                'kpis'              => $kpis,
                'has_data'          => !empty($topLocalisations) && !empty($volumeParPays),
            ];
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPER
    // ─────────────────────────────────────────────────────────────────────────

    private function whereClause(array $marketIds): string
    {
        if (empty($marketIds)) return '';
        $ids = implode(',', array_map('intval', $marketIds));
        return "AND a.market_id IN ({$ids})";
    }
}

