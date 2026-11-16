<?php

namespace App\Service\Geo;

/**
 * Service de préparation des données pour la heatmap MapLibre.
 *
 * Deux modes :
 *   MODE_DENSITY : un point par alerte, poids = 1 (densité pure)
 *   MODE_SCORE   : un point par alerte, poids = score GEI normalisé (densité pondérée)
 *
 * Les deux modes sont statistiquement distincts :
 *   - beaucoup d'alertes ≠ risque élevé
 *   - une zone peut avoir peu d'alertes mais des scores critiques
 *
 * Format de sortie : GeoJSON FeatureCollection (points uniquement).
 */
final class GeoHeatmapService
{
    public const MODE_DENSITY = 'density';
    public const MODE_SCORE   = 'score';

    public function __construct(
        private readonly GeoAggregationService $aggregation,
    ) {}

    /**
     * Retourne un GeoJSON FeatureCollection pour la heatmap MapLibre.
     *
     * @param string $mode  'density' ou 'score'
     * @param int    $limit Nombre maximum de points (perf browser)
     */
    public function getHeatmapGeoJson(array $filters = [], string $mode = self::MODE_DENSITY, int $limit = 2000): array
    {
        // Alertes avec coordonnées précises (localité saisie)
        $precise = $this->aggregation->getGeolocatedAlerts($filters, $limit);

        // Fallback : centroïdes des marchés pour les alertes sans coordonnées
        $centroids = $this->aggregation->getMarketCentroidAlerts($filters);

        $features = [];

        // Points précis
        foreach ($precise as $row) {
            $weight = $this->computeWeight($row, $mode);
            $features[] = $this->makeFeature(
                (float) $row['latitude'],
                (float) $row['longitude'],
                $weight,
                [
                    'id'               => (int)    $row['id'],
                    'niveau_priorite'  => (string) $row['niveau_priorite'],
                    'categorie'        => $row['categorie'],
                    'statut'           => $row['statut'],
                    'type_localisation'=> $row['type_localisation'],
                    'market'           => $row['market_nom'],
                    'score'            => $row['score'] !== null ? (int) $row['score'] : null,
                    'precise'          => true,
                ]
            );
        }

        // Centroïdes (points agrégés par marché)
        foreach ($centroids as $row) {
            $weight = $mode === self::MODE_SCORE
                ? min(1.0, ((float) ($row['score_moyen'] ?? 0)) / 25)
                : min(1.0, ((int) $row['total']) / 50);

            $features[] = $this->makeFeature(
                (float) $row['latitude'],
                (float) $row['longitude'],
                $weight,
                [
                    'market'  => $row['market_nom'],
                    'iso3'    => $row['code_iso3'],
                    'total'   => (int) $row['total'],
                    'score'   => $row['score_moyen'] !== null ? (float) $row['score_moyen'] : null,
                    'precise' => false,
                ]
            );
        }

        return [
            'type'     => 'FeatureCollection',
            'features' => $features,
            'meta'     => [
                'mode'          => $mode,
                'total_points'  => count($features),
                'precise_count' => count($precise),
                'centroid_count'=> count($centroids),
            ],
        ];
    }

    /**
     * Calcule le poids d'un point selon le mode.
     * Normalisé entre 0 et 1 pour MapLibre heatmap-weight.
     */
    private function computeWeight(array $row, string $mode): float
    {
        if ($mode === self::MODE_SCORE) {
            // Score GEI : 1–25. On normalise sur 25.
            $score = $row['score'] !== null ? (int) $row['score'] : 5;
            return min(1.0, max(0.1, $score / 25));
        }

        // MODE_DENSITY : toutes les alertes ont le même poids de base,
        // légèrement augmenté pour les priorités critiques
        return match ($row['niveau_priorite'] ?? 'faible') {
            'critique' => 1.0,
            'eleve'    => 0.75,
            'modere'   => 0.5,
            default    => 0.25,
        };
    }

    private function makeFeature(float $lat, float $lng, float $weight, array $properties): array
    {
        return [
            'type'       => 'Feature',
            'geometry'   => [
                'type'        => 'Point',
                'coordinates' => [$lng, $lat], // GeoJSON : [lon, lat]
            ],
            'properties' => array_merge($properties, ['weight' => $weight]),
        ];
    }
}
