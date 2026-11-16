<?php

namespace App\Service\Geo;

/**
 * Service pour la carte mondiale détaillée MapLibre.
 *
 * Prépare les données de toutes les couches activables :
 *   - Couche alertes (points + propriétés)
 *   - Couche corridors / type de localisation
 *   - Couche pays / marchés (polygones ZoneGeo)
 *
 * Chaque couche est un GeoJSON FeatureCollection indépendant.
 * Le navigateur active/désactive les couches sans rechargement.
 */
final class GeoWorldMapService
{
    public function __construct(
        private readonly GeoAggregationService $aggregation,
    ) {}

    /**
     * Couche principale : toutes les alertes géolocalisées (précises + centroïdes).
     * Utilisée pour le clustering MapLibre et les popups.
     */
    public function getAlertsLayer(array $filters = [], int $limit = 2000): array
    {
        $precise   = $this->aggregation->getGeolocatedAlerts($filters, $limit);
        $centroids = $this->aggregation->getMarketCentroidAlerts($filters);

        $features = [];

        // Points précis
        foreach ($precise as $row) {
            $features[] = [
                'type'     => 'Feature',
                'geometry' => [
                    'type'        => 'Point',
                    'coordinates' => [(float) $row['longitude'], (float) $row['latitude']],
                ],
                'properties' => [
                    'id'               => (int) $row['id'],
                    'type'             => 'alert_precise',
                    'code_gei'         => $row['date_creation'], // placeholder visible en popup
                    'score'            => $row['score'] !== null ? (int) $row['score'] : null,
                    'niveau_priorite'  => $row['niveau_priorite'] ?? 'faible',
                    'categorie'        => $row['categorie'],
                    'statut'           => $row['statut'],
                    'type_localisation'=> $row['type_localisation'],
                    'port_corridor'    => $row['port_corridor'],
                    'localite'         => $row['localite'],
                    'market'           => $row['market_nom'],
                    'iso3'             => $row['market_iso3'],
                    'date'             => $row['date_creation'],
                    'color'            => $this->priorityColor($row['niveau_priorite'] ?? 'faible'),
                    'cluster_weight'   => $this->clusterWeight($row['niveau_priorite'] ?? 'faible'),
                ],
            ];
        }

        // Centroïdes de marché (alertes sans coordonnées précises)
        foreach ($centroids as $row) {
            $features[] = [
                'type'     => 'Feature',
                'geometry' => [
                    'type'        => 'Point',
                    'coordinates' => [(float) $row['longitude'], (float) $row['latitude']],
                ],
                'properties' => [
                    'type'          => 'market_centroid',
                    'market'        => $row['market_nom'],
                    'iso3'          => $row['code_iso3'],
                    'total'         => (int) $row['total'],
                    'critique'      => (int) $row['critique'],
                    'score'         => $row['score_moyen'] !== null ? (float) $row['score_moyen'] : null,
                    'color'         => (int) $row['critique'] > 0 ? '#ef4444' : '#3b82f6',
                    'cluster_weight'=> min(10, (int) $row['total']),
                ],
            ];
        }

        return [
            'type'     => 'FeatureCollection',
            'features' => $features,
            'meta'     => [
                'precise_count'  => count($precise),
                'centroid_count' => count($centroids),
                'total'          => count($features),
            ],
        ];
    }

    /**
     * Couche corridors et infrastructures (ports, aéroports).
     * Points de localisation précis de type non-localité.
     */
    public function getInfrastructureLayer(array $filters = []): array
    {
        $rows = $this->aggregation->getGeolocatedAlerts(
            array_merge($filters, ['typeLocalisation' => null]),
            500
        );

        $features = [];
        foreach ($rows as $row) {
            $type = $row['type_localisation'];
            if (!in_array($type, ['port', 'aeroport', 'corridor'], true)) {
                continue;
            }

            $features[] = [
                'type'     => 'Feature',
                'geometry' => [
                    'type'        => 'Point',
                    'coordinates' => [(float) $row['longitude'], (float) $row['latitude']],
                ],
                'properties' => [
                    'type'    => $type,
                    'nom'     => $row['port_corridor'] ?? $row['localite'],
                    'market'  => $row['market_nom'],
                    'iso3'    => $row['market_iso3'],
                    'total'   => 1,
                    'icon'    => $this->infraIcon($type),
                    'color'   => $this->infraColor($type),
                ],
            ];
        }

        // Dédupliquer par nom + type
        $seen     = [];
        $filtered = [];
        foreach ($features as $f) {
            $key = $f['properties']['type'] . '|' . ($f['properties']['nom'] ?? '');
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $filtered[] = $f;
            }
        }

        return ['type' => 'FeatureCollection', 'features' => $filtered];
    }

    /**
     * Résumé par marché pour la couche choroplèthe MapLibre
     * (remplissage des polygones si ZoneGeo contient un polygonWkt).
     */
    public function getMarketsLayer(array $filters = []): array
    {
        $rows = $this->aggregation->countByMarket($filters);

        $features = [];
        foreach ($rows as $row) {
            $features[] = [
                'type'     => 'Feature',
                'geometry' => [
                    'type'        => 'Point',
                    'coordinates' => [(float) $row['longitude'], (float) $row['latitude']],
                ],
                'properties' => [
                    'iso3'      => $row['code_iso3'],
                    'nom'       => $row['nom'],
                    'continent' => $row['continent'],
                    'region'    => $row['region'],
                    'total'     => (int) $row['total'],
                    'critique'  => (int) $row['critique'],
                    'eleve'     => (int) $row['eleve'],
                    'transmis'  => (int) $row['transmis'],
                    'score'     => $row['score_moyen'] !== null ? (float) $row['score_moyen'] : null,
                    'color'     => $this->intensityColor((int) $row['total']),
                ],
            ];
        }

        return ['type' => 'FeatureCollection', 'features' => $features];
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function priorityColor(string $priority): string
    {
        return match ($priority) {
            'critique' => '#ef4444',
            'eleve'    => '#f97316',
            'modere'   => '#f59e0b',
            default    => '#3b82f6',
        };
    }

    private function clusterWeight(string $priority): int
    {
        return match ($priority) {
            'critique' => 4,
            'eleve'    => 3,
            'modere'   => 2,
            default    => 1,
        };
    }

    private function infraIcon(string $type): string
    {
        return match ($type) {
            'port'     => 'harbor',
            'aeroport' => 'airport',
            'corridor' => 'road',
            default    => 'place',
        };
    }

    private function infraColor(string $type): string
    {
        return match ($type) {
            'port'     => '#0891b2',
            'aeroport' => '#7c3aed',
            'corridor' => '#16a34a',
            default    => '#64748b',
        };
    }

    private function intensityColor(int $total): string
    {
        return match (true) {
            $total === 0  => '#f0f4f8',
            $total <= 5   => '#bfdbfe',
            $total <= 15  => '#60a5fa',
            $total <= 30  => '#f59e0b',
            default       => '#ef4444',
        };
    }
}
