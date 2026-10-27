<?php

namespace App\Controller;

use App\Entity\Alert;
use App\Entity\Market;
use App\Enum\AlertStatut;
use App\Enum\AlertUrgence;
use App\Enum\NiveauPriorite;
use App\Enum\TypeAlerte;
use App\Repository\AlertRepository;
use App\Repository\ListeReferenceValeurRepository;
use App\Repository\MarketRepository;
use App\Repository\ZoneGeoRepository;
use App\Service\CryptoService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Intl\Countries;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

#[Route('/carte')]
#[IsGranted('ROLE_PFT')]
class CarteController extends AbstractController
{
    public function __construct(
        private readonly CryptoService $crypto,
    ) {
    }

    #[Route('/', name: 'app_carte_index', methods: ['GET'])]
    public function index(
        Request          $request,
        MarketRepository $marketRepo,
        AlertRepository  $alertRepo,
        ListeReferenceValeurRepository $listeReferenceRepo,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            throw $this->createAccessDeniedException();
        }

        $marcheRaw = $request->query->all('marche');
        if (empty($marcheRaw)) {
            $marcheRaw = $request->query->all('markets');
        }
        if (empty($marcheRaw)) {
            $single = $request->query->get('marche') ?? $request->query->get('markets');
            if ($single !== null && $single !== '') {
                $marcheRaw = is_array($single) ? $single : explode(',', (string) $single);
            }
        }
        $marcheArray = array_values(array_filter(array_map('intval', (array) $marcheRaw)));

        $filters = [
            'marche'      => $marcheArray,
            'markets'     => $marcheArray,
            'categorie'   => $request->query->get('categorie'),
            'priorite'    => $request->query->get('priorite'),
            'statut'      => $request->query->get('statut'),
            'urgence'     => $request->query->get('urgence'),
            'typeAlerte'  => $request->query->get('typeAlerte'),
            'scoreMin'    => $request->query->get('scoreMin'),
            'scoreMax'    => $request->query->get('scoreMax'),
            'origine'     => $request->query->get('origine'),
            'dateDebut'   => $request->query->get('dateDebut'),
            'dateFin'     => $request->query->get('dateFin'),
        ];

        $userMarkets = $marketRepo->findActifs();

        $totalAlertes = (int) $alertRepo->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.deletedAt IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
        $totalPays = count($userMarkets);

        return $this->render('carte/index.html.twig', [
            'filters'      => $filters,
            'markets'      => $userMarkets,
            'categories'   => $listeReferenceRepo->findActivesByType('categorie'),
            'niveaux'      => NiveauPriorite::cases(),
            'statuts'      => AlertStatut::cases(),
            'urgences'     => AlertUrgence::cases(),
            'typeAlertes'  => TypeAlerte::cases(),
            'totalAlertes' => $totalAlertes,
            'totalPays'    => $totalPays,
        ]);
    }

    /**
     * API — données de tous les pays avec comptage d'alertes et répartition par catégorie.
     * Utilisé par Leaflet pour placer les marqueurs et remplir les popups.
     */
    #[Route('/api/zones', name: 'app_carte_zones_api', methods: ['GET'])]
    public function apiZones(
        Request          $request,
        ZoneGeoRepository $zoneGeoRepo,
        AlertRepository  $alertRepo,
        MarketRepository $marketRepo,
        CacheInterface   $cache,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            return new JsonResponse([], 403);
        }

        $marcheRaw = $request->query->all('marche');
        if (empty($marcheRaw)) {
            $marcheRaw = $request->query->all('markets');
        }
        if (empty($marcheRaw)) {
            $single = $request->query->get('marche') ?? $request->query->get('markets');
            if ($single !== null && $single !== '') {
                $marcheRaw = is_array($single) ? $single : explode(',', (string) $single);
            }
        }
        $marcheArray = array_values(array_filter(array_map('intval', (array) $marcheRaw)));

        $filters = [
            'marche'     => $marcheArray,
            'markets'    => $marcheArray,
            'categorie'  => $request->query->get('categorie'),
            'priorite'   => $request->query->get('priorite'),
            'statut'     => $request->query->get('statut'),
            'urgence'    => $request->query->get('urgence'),
            'typeAlerte' => $request->query->get('typeAlerte'),
            'scoreMin'   => $request->query->get('scoreMin'),
            'scoreMax'   => $request->query->get('scoreMax'),
            'origine'    => $request->query->get('origine'),
            'dateDebut'  => $request->query->get('dateDebut'),
            'dateFin'    => $request->query->get('dateFin'),
        ];

        $allowedMarkets = $marketRepo->findActifs();
        if ($allowedMarkets === []) {
            return new JsonResponse([]);
        }
        $filters['allowedMarkets'] = array_map(
            static fn(Market $market): int => (int) $market->getId(),
            $allowedMarkets
        );

        $allowedMarketIds = array_map(
            static fn(Market $market): int => (int) $market->getId(),
            $allowedMarkets
        );
        sort($allowedMarketIds);
        $cacheKey = 'gei_map_zones_' . hash('sha256', json_encode([
            'allowedMarkets' => $allowedMarketIds,
            'filters' => $filters,
        ], JSON_THROW_ON_ERROR));

        $result = $cache->get($cacheKey, function (ItemInterface $item) use ($zoneGeoRepo, $alertRepo, $filters): array {
            $item->expiresAfter(20);
            $zones = $zoneGeoRepo->findWithAlertCountFiltered($filters);
            $marketIds = array_map(static fn(array $zone): int => (int) ($zone['marketId'] ?? 0), $zones);
            $statsByMarket = $alertRepo->getMapStatsByMarkets($marketIds, $filters);

            $result = [];
            foreach ($zones as $zone) {
                $marketId = (int) ($zone['marketId'] ?? 0);
                if ($marketId === 0) {
                    continue;
                }
                $stats = $statsByMarket[$marketId] ?? ['categories' => [], 'corridors' => [], 'localities' => []];
                $result[] = [
                    'id'          => (int) $zone['id'],
                    'marketId'    => (int) $zone['marketId'],
                    'nom'         => $zone['nom'] ?? '',
                    'codeIso3'    => $zone['codeIso3'] ?? '',
                    'polygonWkt'  => $zone['polygonWkt'] ?? null,
                    'latitude'    => (float) $zone['latitude'],
                    'longitude'   => (float) $zone['longitude'],
                    'alertCount'  => (int) $zone['alertCount'],
                    'maxPriority' => $zone['maxPriority'] ?? 'faible',
                    'categories'  => $stats['categories'],
                    'corridors'   => $stats['corridors'],
                    'localities'  => $stats['localities'],
                ];
            }

            return $result;
        });

        return new JsonResponse($result);
    }

    /**
     * API — liste des alertes d'un marché (pour le panneau latéral au clic).
     */
    #[Route('/api/alertes', name: 'app_carte_alertes_api', methods: ['GET'])]
    public function apiAlertes(
        Request         $request,
        AlertRepository $alertRepo,
        MarketRepository $marketRepo,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            return new JsonResponse([], 403);
        }

        $marketFilter = $request->query->get('marketId') ?: $request->query->get('market');
        $routeCountries = [];
        if (is_scalar($marketFilter) && ctype_digit((string) $marketFilter)) {
            $market = $marketRepo->find((int) $marketFilter);
            if ($market instanceof Market) {
                $routeCountries[] = $market->getCodeIso3();
                $routeCountries[] = $market->getNom();
                try {
                    $alpha2 = Countries::getAlpha2Code($market->getCodeIso3());
                    $routeCountries[] = $alpha2;
                    $routeCountries[] = Countries::getName($alpha2, 'fr');
                    $routeCountries[] = Countries::getName($alpha2, 'en');
                } catch (\Symfony\Component\Intl\Exception\MissingResourceException) {
                    // Keep the market name and ISO3 fallback for non-standard market codes.
                }
            }
        }

        $filters = [
            'market'         => $marketFilter,
            'routeCountries'  => array_values(array_unique(array_filter($routeCountries))),
            'categorie'      => $request->query->get('categorie'),
            'niveauPriorite' => $request->query->get('priorite') ?: $request->query->get('niveauPriorite'),
            'statut'         => $request->query->get('statut'),
            'urgence'        => $request->query->get('urgence'),
            'typeAlerte'     => $request->query->get('typeAlerte'),
            'scoreMin'       => $request->query->get('scoreMin'),
            'scoreMax'       => $request->query->get('scoreMax'),
            'origine'        => $request->query->get('origine'),
            'dateDebut'      => $request->query->get('dateDebut'),
            'dateFin'        => $request->query->get('dateFin'),
        ];

        $alerts = $alertRepo->findForUser($user, $filters);

        return new JsonResponse(array_map(fn(Alert $a) => [
            'id'             => $a->getId(),
            'token'          => $this->crypto->encryptId($a->getId(), 'alert'),
            'codeGei'        => $a->getCodeGei() ?? 'BROUILLON',
            'statut'         => $a->getStatut()?->value ?? 'nouveau',
            'statutLabel'    => $a->getStatut()?->label() ?? '—',
            'score'          => $a->getEffectiveScore(),
            'niveauPriorite' => $a->getEffectiveNiveauPriorite()?->value ?? 'faible',
            'prioriteLabel'  => $a->getEffectiveNiveauPriorite()?->label() ?? '—',
            'categorie'      => $a->getCategorie() ?? '',
            'categorieLabel' => $a->getCategorie() ?? '—',
            'dateCreation'   => $a->getDateCreation()->format('d/m/Y'),
            'resumeExecutif' => mb_substr($a->getResumeExecutif() ?? '', 0, 160),
            'portCorridor'   => $a->getPortCorridor() ?? '—',
            'localite'       => $a->getLocalite(),
        ], $alerts));
    }

    /**
     * API — parcours indicatifs entre les marchés des alertes multi-marchés.
     * Les compteurs de la carte restent basés sur le marché principal ; cette
     * route sert à visualiser les corridors et flux transfrontaliers avec leurs métadonnées.
     */
    #[Route('/api/parcours', name: 'app_carte_parcours_api', methods: ['GET'])]
    public function apiParcours(
        Request $request,
        EntityManagerInterface $em,
        MarketRepository $marketRepo,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            return new JsonResponse([], 403);
        }

        $selectedRaw = $request->query->all('marche');
        if (empty($selectedRaw)) {
            $selectedRaw = $request->query->all('markets');
        }
        $selectedIds = array_values(array_filter(array_map('intval', (array) $selectedRaw)));
        $allowedMarkets = $marketRepo->findActifs();
        $allowedIds = array_map(static fn(Market $market): int => (int) $market->getId(), $allowedMarkets);
        $mainIds = $selectedIds !== [] ? array_values(array_intersect($selectedIds, $allowedIds)) : $allowedIds;

        if ($mainIds === []) {
            return new JsonResponse([]);
        }

        $whereClauses = ['a.deleted_at IS NULL'];
        $params = [];

        if ($cat = $request->query->get('categorie')) {
            $whereClauses[] = "a.categorie = ?";
            $params[] = $cat;
        }
        if ($prio = $request->query->get('priorite')) {
            $whereClauses[] = "COALESCE(a.niveau_priorite_surcharge, a.niveau_priorite) = ?";
            $params[] = $prio;
        }
        if ($statut = $request->query->get('statut')) {
            $whereClauses[] = "a.statut = ?";
            $params[] = $statut;
        }
        if ($urgence = $request->query->get('urgence')) {
            $whereClauses[] = "a.urgence = ?";
            $params[] = $urgence;
        }
        if ($typeAlerte = $request->query->get('typeAlerte')) {
            $whereClauses[] = "a.type_alerte = ?";
            $params[] = $typeAlerte;
        }
        if ($scoreMin = $request->query->get('scoreMin')) {
            $whereClauses[] = "COALESCE(a.score_surcharge, a.score_gei) >= ?";
            $params[] = (int) $scoreMin;
        }
        if ($scoreMax = $request->query->get('scoreMax')) {
            $whereClauses[] = "COALESCE(a.score_surcharge, a.score_gei) <= ?";
            $params[] = (int) $scoreMax;
        }
        if ($origine = $request->query->get('origine')) {
            $whereClauses[] = "a.origine = ?";
            $params[] = $origine;
        }
        if ($dateDebut = $request->query->get('dateDebut')) {
            $whereClauses[] = "a.date_creation >= ?";
            $params[] = $dateDebut . ' 00:00:00';
        }
        if ($dateFin = $request->query->get('dateFin')) {
            $whereClauses[] = "a.date_creation <= ?";
            $params[] = $dateFin . ' 23:59:59';
        }

        $whereSql = implode(' AND ', $whereClauses);

        $rows = $em->getConnection()->fetchAllAssociative(
            "SELECT a.id, a.code_gei, a.port_corridor, a.localite, a.type_localisation, a.categorie, a.parcours_countries,
                    COALESCE(a.niveau_priorite_surcharge, a.niveau_priorite) AS niveau_priorite,
                    COALESCE(a.score_surcharge, a.score_gei) AS score,
                    principal.id AS principal_id, principal.nom AS principal, principal.code_iso3 AS principal_iso3,
                    COALESCE(NULLIF(principal_zone.latitude, 0), CASE principal.code_iso3
                        WHEN 'BEN' THEN 9.3077 WHEN 'TGO' THEN 8.6195 WHEN 'GHA' THEN 7.9465
                        WHEN 'BFA' THEN 12.2383 WHEN 'MLI' THEN 17.5707 WHEN 'NER' THEN 17.6078
                        WHEN 'SEN' THEN 14.4974 WHEN 'CIV' THEN 7.5400 WHEN 'GIN' THEN 9.9456
                        WHEN 'NGA' THEN 9.0820 WHEN 'CMR' THEN 7.3697 WHEN 'COD' THEN -4.0383
                        WHEN 'AGO' THEN -11.2027 ELSE 8.0000 END) AS principal_lat,
                    COALESCE(NULLIF(principal_zone.longitude, 0), CASE principal.code_iso3
                        WHEN 'BEN' THEN 2.3158 WHEN 'TGO' THEN 0.8248 WHEN 'GHA' THEN -1.0232
                        WHEN 'BFA' THEN -1.5616 WHEN 'MLI' THEN -3.9962 WHEN 'NER' THEN 8.0817
                        WHEN 'SEN' THEN -14.4524 WHEN 'CIV' THEN -5.5471 WHEN 'GIN' THEN -9.6966
                        WHEN 'NGA' THEN 8.6753 WHEN 'CMR' THEN 12.3547 WHEN 'COD' THEN 21.7587
                        WHEN 'AGO' THEN 17.8739 ELSE 2.0000 END) AS principal_lng,
                    am.ordre, associe.id AS associe_id, associe.nom AS associe, associe.code_iso3 AS associe_iso3,
                    associe_zone.latitude AS associe_lat,
                    associe_zone.longitude AS associe_lng
             FROM alert a
             JOIN market principal ON principal.id = a.market_id
             LEFT JOIN (
                 SELECT market_id, AVG(latitude) AS latitude, AVG(longitude) AS longitude
                 FROM zone_geo
                 WHERE latitude IS NOT NULL AND latitude != 0
                 GROUP BY market_id
             ) principal_zone ON principal_zone.market_id = principal.id
             LEFT JOIN alert_market am ON am.alert_id = a.id AND am.role = 'associe'
             LEFT JOIN market associe ON associe.id = am.market_id
             LEFT JOIN (
                 SELECT market_id, AVG(latitude) AS latitude, AVG(longitude) AS longitude
                 FROM zone_geo
                 WHERE latitude IS NOT NULL AND latitude != 0
                 GROUP BY market_id
             ) associe_zone ON associe_zone.market_id = associe.id
             WHERE {$whereSql}
               AND (
                   am.id IS NOT NULL
                   OR (a.parcours_countries IS NOT NULL AND JSON_LENGTH(a.parcours_countries) > 0)
               )
             ORDER BY a.id, am.ordre",
            $params
        );

        $marketRows = $em->getConnection()->fetchAllAssociative(
            'SELECT m.id, m.nom, m.code_iso3,
                    AVG(NULLIF(z.latitude, 0)) AS latitude,
                    AVG(NULLIF(z.longitude, 0)) AS longitude
             FROM market m
             LEFT JOIN zone_geo z ON z.market_id = m.id
             WHERE m.actif = 1
             GROUP BY m.id, m.nom, m.code_iso3'
        );
        $marketData = [];
        foreach ($marketRows as $marketRow) {
            [$fallbackLat, $fallbackLng] = self::fallbackMarketCoordinates((string) $marketRow['code_iso3']);
            $marketData[(int) $marketRow['id']] = [
                'nom' => (string) $marketRow['nom'],
                'iso3' => strtoupper((string) $marketRow['code_iso3']),
                'latitude' => $marketRow['latitude'] !== null ? (float) $marketRow['latitude'] : $fallbackLat,
                'longitude' => $marketRow['longitude'] !== null ? (float) $marketRow['longitude'] : $fallbackLng,
            ];
        }

        $marketLookup = self::buildMarketLookup($marketData);
        $routes = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            if (!isset($routes[$id])) {
                $storedCountries = $row['parcours_countries'];
                if (is_string($storedCountries)) {
                    $storedCountries = json_decode($storedCountries, true);
                }
                $routes[$id] = [
                    'id'            => $id,
                    'codeGei'       => $row['code_gei'] ?? 'BROUILLON',
                    'portCorridor'  => $row['port_corridor'] ?? null,
                    'localite'      => $row['localite'] ?? null,
                    'typeLocalisation' => $row['type_localisation'] ?? null,
                    'categorie'     => $row['categorie'] ?? null,
                    'priorite'      => $row['niveau_priorite'] ?? 'faible',
                    'score'         => (int) ($row['score'] ?? 0),
                    'principalId'   => (int) $row['principal_id'],
                    'principal'     => $row['principal'],
                    'principalIso3' => $row['principal_iso3'],
                    'principalCoordinates' => [(float) $row['principal_lat'], (float) $row['principal_lng']],
                    'storedCountries' => is_array($storedCountries) ? $storedCountries : [],
                    'associatedMarkets' => [],
                ];
            }
            if ($row['associe_id'] !== null) {
                $routes[$id]['associatedMarkets'][] = [
                    'id' => (int) $row['associe_id'],
                    'ordre' => $row['ordre'] !== null ? (int) $row['ordre'] : PHP_INT_MAX,
                ];
            }
        }

        $result = [];
        foreach ($routes as $route) {
            $path = self::resolveRouteMarkets(
                $route['storedCountries'],
                $route['associatedMarkets'],
                $marketLookup,
                $marketData,
                $route['principalId'],
            );
            $routeMarketIds = array_merge([$route['principalId']], array_column($path, 'id'));
            if (array_intersect($routeMarketIds, $mainIds) === []) {
                continue;
            }

            $route['points'] = [$route['principalCoordinates']];
            $route['markets'] = [$route['principal']];
            $route['marketIds'] = [$route['principalId']];
            $route['iso3s'] = [$route['principalIso3']];
            foreach ($path as $pathMarket) {
                $market = $marketData[$pathMarket['id']] ?? null;
                if ($market === null || $market['latitude'] === null || $market['longitude'] === null) {
                    continue;
                }
                $route['points'][] = [$market['latitude'], $market['longitude']];
                $route['markets'][] = $market['nom'];
                $route['marketIds'][] = $pathMarket['id'];
                $route['iso3s'][] = $market['iso3'];
            }

            unset($route['principalCoordinates'], $route['storedCountries'], $route['associatedMarkets']);
            if (count($route['points']) > 1) {
                $result[] = $route;
            }
        }

        return new JsonResponse($result);
    }

    private static function fallbackMarketCoordinates(string $iso3): array
    {
        return match (strtoupper($iso3)) {
            'BEN' => [9.3077, 2.3158],
            'TGO' => [8.6195, 0.8248],
            'GHA' => [7.9465, -1.0232],
            'BFA' => [12.2383, -1.5616],
            'MLI' => [17.5707, -3.9962],
            'NER' => [17.6078, 8.0817],
            'SEN' => [14.4974, -14.4524],
            'CIV' => [7.5400, -5.5471],
            'GIN' => [9.9456, -9.6966],
            'NGA' => [9.0820, 8.6753],
            'CMR' => [7.3697, 12.3547],
            'COD' => [-4.0383, 21.7587],
            'AGO' => [-11.2027, 17.8739],
            default => [null, null],
        };
    }

    private static function buildMarketLookup(array $marketData): array
    {
        $lookup = [];
        foreach ($marketData as $marketId => $market) {
            $lookup['iso3:' . $market['iso3']] = (int) $marketId;
            $lookup['name:' . self::normalizeCountryName($market['nom'])] = (int) $marketId;
            try {
                $lookup['iso2:' . Countries::getAlpha2Code($market['iso3'])] = (int) $marketId;
            } catch (\Symfony\Component\Intl\Exception\MissingResourceException) {
                // Some configured markets may use a non-standard ISO3 code.
            }
        }

        return $lookup;
    }

    private static function normalizeCountryName(string $name): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        return preg_replace('/[^a-z0-9]/', '', strtolower($ascii === false ? $name : $ascii)) ?? '';
    }

    private static function resolveRouteMarkets(
        array $storedCountries,
        array $associatedMarkets,
        array $marketLookup,
        array $marketData,
        int $principalId,
    ): array {
        $path = [];
        foreach ($storedCountries as $country) {
            $value = trim((string) $country);
            if ($value === '') {
                continue;
            }

            $upper = strtoupper($value);
            $marketId = null;
            if (strlen($upper) === 3) {
                $marketId = $marketLookup['iso3:' . $upper] ?? null;
            } elseif (strlen($upper) === 2) {
                $marketId = $marketLookup['iso2:' . $upper] ?? null;
                if ($marketId === null) {
                    try {
                        $iso3 = Countries::getAlpha3Code($upper);
                        $marketId = $marketLookup['iso3:' . $iso3] ?? null;
                    } catch (\Symfony\Component\Intl\Exception\MissingResourceException) {
                        $marketId = null;
                    }
                }
            }
            $marketId ??= $marketLookup['name:' . self::normalizeCountryName($value)] ?? null;

            if ($marketId !== null && $marketId !== $principalId && isset($marketData[$marketId])) {
                $path[$marketId] = ['id' => $marketId];
            }
        }

        if ($path !== []) {
            return array_values($path);
        }

        usort($associatedMarkets, static fn(array $left, array $right): int => $left['ordre'] <=> $right['ordre']);
        foreach ($associatedMarkets as $associatedMarket) {
            $marketId = $associatedMarket['id'];
            if ($marketId !== $principalId && isset($marketData[$marketId])) {
                $path[$marketId] = ['id' => $marketId];
            }
        }

        return array_values($path);
    }
}
