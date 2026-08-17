<?php

namespace App\Service;

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
     */
    public function getVolumeParPays(string $debut, string $fin, array $marketIds = []): array
    {
        $key = 'stat_volume_pays_' . md5($debut . $fin . implode(',', $marketIds));
        return $this->cache->get($key, function (ItemInterface $item) use ($debut, $fin, $marketIds) {
            $item->expiresAfter(self::TTL);
            $where = $this->whereClause($marketIds);
            return $this->conn()->fetchAllAssociative(
                "SELECT m.nom AS pays, m.code_iso3 AS iso3, COUNT(a.id) AS nb
                 FROM alert a
                 JOIN market m ON m.id = a.market_id
                 WHERE a.date_creation BETWEEN :debut AND :fin {$where}
                 GROUP BY m.id, m.nom, m.code_iso3
                 ORDER BY nb DESC",
                ['debut' => $debut . ' 00:00:00', 'fin' => $fin . ' 23:59:59']
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
                 WHERE c.date_activation BETWEEN :debut AND :fin",
                ['debut' => $debut . ' 00:00:00', 'fin' => $fin . ' 23:59:59']
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
     */
    public function getTopCorridors(string $debut, string $fin, array $marketIds = [], int $limit = 10): array
    {
        $key = 'stat_corridors_' . md5($debut . $fin . implode(',', $marketIds) . $limit);
        return $this->cache->get($key, function (ItemInterface $item) use ($debut, $fin, $marketIds, $limit) {
            $item->expiresAfter(self::TTL);
            $where = $this->whereClause($marketIds);
            return $this->conn()->fetchAllAssociative(
                "SELECT COALESCE(a.port_corridor, 'Non renseigné') AS corridor, COUNT(a.id) AS nb
                 FROM alert a
                 WHERE a.port_corridor IS NOT NULL
                   AND a.port_corridor != ''
                   AND a.date_creation BETWEEN :debut AND :fin {$where}
                 GROUP BY a.port_corridor
                 ORDER BY nb DESC
                 LIMIT {$limit}",
                ['debut' => $debut . ' 00:00:00', 'fin' => $fin . ' 23:59:59']
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
                 WHERE a.statut IN ('transmis', 'archive', 'clos')
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
    // HELPER
    // ─────────────────────────────────────────────────────────────────────────

    private function whereClause(array $marketIds): string
    {
        if (empty($marketIds)) return '';
        $ids = implode(',', array_map('intval', $marketIds));
        return "AND a.market_id IN ({$ids})";
    }
}
