<?php

namespace App\Service\Geo;

/**
 * Service de préparation des données pour la carte choroplèthe ECharts.
 *
 * Calcule les seuils de couleur dynamiquement (quantiles) à partir
 * des données réelles — jamais arbitrairement.
 *
 * Format de sortie compatible avec ECharts visualMap + map series.
 */
final class GeoChoroplethService
{
    public function __construct(
        private readonly GeoAggregationService $aggregation,
    ) {}

    /**
     * Données choroplèthes pour la carte du monde (niveau pays).
     * Retourne le format attendu par ECharts series[type=map].
     *
     * @return array{
     *   series: array<int, array{name: string, value: int, details: array}>,
     *   visualMap: array{min: int, max: int, pieces: array},
     *   stats: array
     * }
     */
    public function getWorldChoropleth(array $filters = []): array
    {
        $rows = $this->aggregation->countByMarket($filters);

        $series = [];
        $values = [];

        foreach ($rows as $row) {
            $count = (int) $row['total'];
            $values[] = $count;

            $series[] = [
                'name'  => $row['nom'],
                'iso3'  => $row['code_iso3'],
                'value' => $count,
                'details' => [
                    'critique'    => (int) $row['critique'],
                    'eleve'       => (int) $row['eleve'],
                    'modere'      => (int) $row['modere'],
                    'faible'      => (int) $row['faible'],
                    'score_moyen' => $row['score_moyen'] !== null ? (float) $row['score_moyen'] : null,
                    'transmis'    => (int) $row['transmis'],
                    'continent'   => $row['continent'],
                    'region'      => $row['region'],
                    'latitude'    => (float) $row['latitude'],
                    'longitude'   => (float) $row['longitude'],
                ],
            ];
        }

        return [
            'series'    => $series,
            'visualMap' => $this->buildVisualMap($values),
            'stats'     => $this->computeStats($rows),
        ];
    }

    /**
     * Données choroplèthes groupées par continent.
     */
    public function getContinentChoropleth(array $filters = []): array
    {
        $rows = $this->aggregation->countByContinent($filters);

        $series = [];
        $values = [];

        foreach ($rows as $row) {
            $count = (int) $row['total'];
            $values[] = $count;

            $series[] = [
                'name'  => $row['continent'],
                'value' => $count,
                'details' => [
                    'nb_pays'     => (int) $row['nb_pays'],
                    'critique'    => (int) $row['critique'],
                    'eleve'       => (int) $row['eleve'],
                    'score_moyen' => $row['score_moyen'] !== null ? (float) $row['score_moyen'] : null,
                ],
            ];
        }

        return [
            'series'    => $series,
            'visualMap' => $this->buildVisualMap($values),
            'stats'     => $this->computeStats($rows),
        ];
    }

    /**
     * Calcule les seuils de la visualMap ECharts par quantiles.
     * 5 niveaux : vide, très faible, faible, moyen, élevé, très élevé.
     */
    private function buildVisualMap(array $values): array
    {
        $nonZero = array_filter($values, fn($v) => $v > 0);

        if (empty($nonZero)) {
            return [
                'min' => 0,
                'max' => 1,
                'pieces' => [
                    ['min' => 0, 'max' => 0,    'label' => 'Aucune',      'color' => '#f0f4f8'],
                    ['min' => 1, 'max' => 999,  'label' => 'Données',     'color' => '#93c5fd'],
                ],
            ];
        }

        sort($nonZero);
        $count = count($nonZero);

        // Quantiles Q1, Q2 (médiane), Q3, Q4 (max)
        $q1  = (int) ceil($nonZero[(int) floor($count * 0.25)]);
        $q2  = (int) ceil($nonZero[(int) floor($count * 0.50)]);
        $q3  = (int) ceil($nonZero[(int) floor($count * 0.75)]);
        $max = (int) max($nonZero);

        return [
            'min' => 0,
            'max' => $max,
            'pieces' => [
                ['min' => 0,      'max' => 0,      'label' => 'Aucune alerte',  'color' => '#f0f4f8'],
                ['min' => 1,      'max' => $q1,    'label' => 'Très faible',    'color' => '#bfdbfe'],
                ['min' => $q1 + 1,'max' => $q2,    'label' => 'Faible',         'color' => '#60a5fa'],
                ['min' => $q2 + 1,'max' => $q3,    'label' => 'Moyen',          'color' => '#f59e0b'],
                ['min' => $q3 + 1,'max' => $max,   'label' => 'Élevé',          'color' => '#ef4444'],
            ],
        ];
    }

    private function computeStats(array $rows): array
    {
        $totals = array_column($rows, 'total');

        return [
            'nb_pays' => count($rows),
            'total'   => array_sum($totals),
            'max'     => !empty($totals) ? max($totals) : 0,
            'min'     => !empty($totals) ? min(array_filter($totals)) : 0,
        ];
    }
}
