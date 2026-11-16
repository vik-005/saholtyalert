<?php

namespace App\Service\Geo;

/**
 * Service de statistiques géospatiales pour le tableau de bord GEO.
 *
 * Prépare les KPIs, graphiques d'évolution et répartitions
 * pour l'interface de dashboard géospatial.
 */
final class GeoStatisticsService
{
    public function __construct(
        private readonly GeoAggregationService $aggregation,
    ) {}

    /**
     * Données complètes pour le tableau de bord géospatial.
     * Un seul appel — le contrôleur n'a pas à orchestrer plusieurs services.
     */
    public function getDashboardData(array $filters = []): array
    {
        $kpis       = $this->aggregation->getGlobalKpis($filters);
        $byMarket   = $this->aggregation->countByMarket($filters);
        $byContinent= $this->aggregation->countByContinent($filters);
        $categories = $this->aggregation->getByCategorie($filters);
        $corridors  = $this->aggregation->getTopCorridors($filters, 10);
        $timeline   = $this->aggregation->getTimelineByMonth($filters, 12);

        return [
            'kpis'           => $this->formatKpis($kpis),
            'topMarkets'     => $this->formatTopMarkets($byMarket, 10),
            'byContinent'    => $this->formatContinent($byContinent),
            'byCategorie'    => $this->formatCategories($categories),
            'topCorridors'   => $this->formatCorridors($corridors),
            'timeline'       => $this->formatTimeline($timeline),
            'coverageRate'   => $this->computeCoverageRate($kpis),
        ];
    }

    /**
     * Données pour le mini-dashboard dans le panneau latéral d'une carte.
     */
    public function getSidebarStats(array $filters = []): array
    {
        $kpis = $this->aggregation->getGlobalKpis($filters);

        return [
            'total'      => (int) $kpis['total'],
            'critique'   => (int) $kpis['critique'],
            'eleve'      => (int) $kpis['eleve'],
            'en_cours'   => (int) $kpis['en_cours'],
            'transmis'   => (int) $kpis['transmis'],
            'nb_pays'    => (int) $kpis['nb_pays'],
            'score_moyen'=> $kpis['score_moyen'] !== null ? (float) $kpis['score_moyen'] : null,
        ];
    }

    // ── Formatage des données pour les templates / JSON ───────────────────

    private function formatKpis(array $kpis): array
    {
        $total = max(1, (int) $kpis['total']);

        return [
            'total'         => (int)   $kpis['total'],
            'nb_pays'       => (int)   $kpis['nb_pays'],
            'critique'      => (int)   $kpis['critique'],
            'eleve'         => (int)   $kpis['eleve'],
            'en_cours'      => (int)   $kpis['en_cours'],
            'validees'      => (int)   $kpis['validees'],
            'transmis'      => (int)   $kpis['transmis'],
            'closes'        => (int)   $kpis['closes'],
            'avec_coords'   => (int)   $kpis['avec_coords'],
            'score_moyen'   => $kpis['score_moyen'] !== null ? (float) $kpis['score_moyen'] : null,
            'taux_critique' => round((int) $kpis['critique'] / $total * 100, 1),
            'taux_transmis' => round((int) $kpis['transmis'] / $total * 100, 1),
            'taux_coords'   => round((int) $kpis['avec_coords'] / $total * 100, 1),
        ];
    }

    private function formatTopMarkets(array $rows, int $limit): array
    {
        $result = [];
        foreach (array_slice($rows, 0, $limit) as $row) {
            if ((int) $row['total'] === 0) {
                continue;
            }
            $result[] = [
                'nom'       => $row['nom'],
                'iso3'      => $row['code_iso3'],
                'continent' => $row['continent'],
                'total'     => (int) $row['total'],
                'critique'  => (int) $row['critique'],
                'eleve'     => (int) $row['eleve'],
                'lat'       => (float) $row['latitude'],
                'lng'       => (float) $row['longitude'],
            ];
        }
        return $result;
    }

    private function formatContinent(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'continent'  => $row['continent'],
                'nb_pays'    => (int) $row['nb_pays'],
                'total'      => (int) $row['total'],
                'critique'   => (int) $row['critique'],
                'score_moyen'=> $row['score_moyen'] !== null ? (float) $row['score_moyen'] : null,
            ];
        }
        return $result;
    }

    private function formatCategories(array $rows): array
    {
        return array_map(fn($r) => [
            'categorie' => $r['categorie'],
            'total'     => (int) $r['total'],
        ], $rows);
    }

    private function formatCorridors(array $rows): array
    {
        return array_map(fn($r) => [
            'corridor'          => $r['corridor'],
            'type_localisation' => $r['type_localisation'],
            'total'             => (int) $r['total'],
            'critique'          => (int) $r['critique'],
        ], $rows);
    }

    private function formatTimeline(array $rows): array
    {
        $labels   = [];
        $totals   = [];
        $critiques= [];
        $transmis = [];

        foreach ($rows as $row) {
            $dt = \DateTimeImmutable::createFromFormat('Y-m', $row['mois']);
            $labels[]    = $dt ? $dt->format('M Y') : $row['mois'];
            $totals[]    = (int) $row['total'];
            $critiques[] = (int) $row['critique'];
            $transmis[]  = (int) $row['transmis'];
        }

        return [
            'labels'   => $labels,
            'totals'   => $totals,
            'critiques'=> $critiques,
            'transmis' => $transmis,
        ];
    }

    private function computeCoverageRate(array $kpis): float
    {
        $total = (int) $kpis['total'];
        if ($total === 0) {
            return 0.0;
        }
        return round((int) $kpis['avec_coords'] / $total * 100, 1);
    }
}
