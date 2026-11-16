<?php

namespace App\Service\Geo;

/**
 * Service de préparation des données de flux géographiques.
 *
 * Représente les parcours transfrontaliers des alertes multi-marchés.
 *
 * RÈGLE FONDAMENTALE : seuls les parcours explicitement saisis
 * (alert_market.role='associe' ou alert.parcours_countries) génèrent
 * des arcs. Aucun trajet n'est inventé à partir de deux coordonnées.
 *
 * Format de sortie : GeoJSON FeatureCollection (LineString).
 */
final class GeoFlowService
{
    public function __construct(
        private readonly GeoAggregationService $aggregation,
    ) {}

    /**
     * Retourne un GeoJSON FeatureCollection contenant les arcs de flux.
     * Chaque Feature est une LineString reliant source → destination(s).
     */
    public function getFlowGeoJson(array $filters = []): array
    {
        $rows = $this->aggregation->getFlowData($filters);

        // Grouper par alert_id pour reconstruire les parcours complets
        $alertPaths = [];
        foreach ($rows as $row) {
            $id = (int) $row['alert_id'];

            if (!isset($alertPaths[$id])) {
                $alertPaths[$id] = [
                    'alert_id'       => $id,
                    'code_gei'       => $row['code_gei'],
                    'score'          => $row['score'] !== null ? (int) $row['score'] : null,
                    'niveau_priorite'=> $row['niveau_priorite'],
                    'categorie'      => $row['categorie'],
                    'statut'         => $row['statut'],
                    'type_localisation' => $row['type_localisation'],
                    'port_corridor'  => $row['port_corridor'],
                    // Source : marché principal
                    'points' => [
                        [
                            'lng'  => (float) $row['src_lng'],
                            'lat'  => (float) $row['src_lat'],
                            'nom'  => $row['src_nom'],
                            'iso3' => $row['src_iso3'],
                        ],
                    ],
                ];
            }

            // Ajouter destination si coordonnées valides
            if ($this->isValidCoord((float) $row['dst_lat'], (float) $row['dst_lng'])) {
                $alertPaths[$id]['points'][] = [
                    'lng'  => (float) $row['dst_lng'],
                    'lat'  => (float) $row['dst_lat'],
                    'nom'  => $row['dst_nom'],
                    'iso3' => $row['dst_iso3'],
                ];
            }
        }

        $features = [];
        foreach ($alertPaths as $path) {
            // Un parcours nécessite au moins 2 points distincts
            if (count($path['points']) < 2) {
                continue;
            }

            // Dédupliquer les points consécutifs identiques
            $coords = $this->deduplicatePoints($path['points']);
            if (count($coords) < 2) {
                continue;
            }

            $features[] = [
                'type'     => 'Feature',
                'geometry' => [
                    'type'        => 'LineString',
                    'coordinates' => array_map(
                        fn($p) => [$p['lng'], $p['lat']],
                        $coords
                    ),
                ],
                'properties' => [
                    'alert_id'       => $path['alert_id'],
                    'code_gei'       => $path['code_gei'],
                    'score'          => $path['score'],
                    'niveau_priorite'=> $path['niveau_priorite'],
                    'categorie'      => $path['categorie'],
                    'statut'         => $path['statut'],
                    'type_localisation' => $path['type_localisation'],
                    'port_corridor'  => $path['port_corridor'],
                    'nb_etapes'      => count($coords),
                    'pays'           => array_map(fn($p) => $p['nom'], $coords),
                    'iso3s'          => array_map(fn($p) => $p['iso3'], $coords),
                    // Couleur selon priorité (utilisée par MapLibre paint)
                    'color'  => $this->priorityColor($path['niveau_priorite']),
                    'width'  => $this->priorityWidth($path['niveau_priorite']),
                ],
            ];
        }

        return [
            'type'     => 'FeatureCollection',
            'features' => $features,
            'meta'     => [
                'total_routes' => count($features),
                'filters'      => array_keys(array_filter($filters)),
            ],
        ];
    }

    /**
     * Points de localisation précis (ports, aéroports) issus des alertes.
     * Représentés comme des marqueurs distincts sur la carte des flux.
     */
    public function getLocationPoints(array $filters = []): array
    {
        $rows = $this->aggregation->getGeolocatedAlerts($filters, 500);

        $features = [];
        foreach ($rows as $row) {
            if ($row['type_localisation'] === null) {
                continue;
            }

            $features[] = [
                'type'     => 'Feature',
                'geometry' => [
                    'type'        => 'Point',
                    'coordinates' => [(float) $row['longitude'], (float) $row['latitude']],
                ],
                'properties' => [
                    'id'               => (int) $row['id'],
                    'type_localisation'=> $row['type_localisation'],
                    'nom'              => $row['port_corridor'] ?? $row['localite'],
                    'market'           => $row['market_nom'],
                    'iso3'             => $row['market_iso3'],
                    'score'            => $row['score'] !== null ? (int) $row['score'] : null,
                    'niveau_priorite'  => $row['niveau_priorite'],
                    'categorie'        => $row['categorie'],
                    'icon'             => $this->localisationIcon($row['type_localisation']),
                ],
            ];
        }

        return ['type' => 'FeatureCollection', 'features' => $features];
    }

    private function deduplicatePoints(array $points): array
    {
        $result = [$points[0]];
        for ($i = 1; $i < count($points); $i++) {
            $prev = $result[count($result) - 1];
            if (abs($points[$i]['lat'] - $prev['lat']) > 0.001
                || abs($points[$i]['lng'] - $prev['lng']) > 0.001) {
                $result[] = $points[$i];
            }
        }
        return $result;
    }

    private function isValidCoord(float $lat, float $lng): bool
    {
        return $lat >= -90 && $lat <= 90
            && $lng >= -180 && $lng <= 180
            && !($lat === 0.0 && $lng === 0.0);
    }

    private function priorityColor(?string $priority): string
    {
        return match ($priority) {
            'critique' => '#ef4444',
            'eleve'    => '#f97316',
            'modere'   => '#f59e0b',
            default    => '#3b82f6',
        };
    }

    private function priorityWidth(?string $priority): int
    {
        return match ($priority) {
            'critique' => 3,
            'eleve'    => 2,
            default    => 1,
        };
    }

    private function localisationIcon(?string $type): string
    {
        return match ($type) {
            'port'     => 'harbor',
            'aeroport' => 'airport',
            'corridor' => 'route',
            default    => 'place',
        };
    }
}
