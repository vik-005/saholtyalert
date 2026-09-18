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
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/carte')]
#[IsGranted('ROLE_PFT')]
class CarteController extends AbstractController
{
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

        // Stats globales pour la légende et le titre
        $totalAlertes = (int) $alertRepo->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.deletedAt IS NULL')
            ->getQuery()
            ->getSingleScalarResult();

        $totalPays = $marketRepo->count(['actif' => true]);

        $userMarkets = $user->getRole() === \App\Enum\UserRoleEnum::PFT
            ? $user->getAllManagedMarkets()
            : $marketRepo->findActifs();

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

        $allowedMarkets = $user->getRole() === \App\Enum\UserRoleEnum::PFT
            ? $user->getAllManagedMarkets()
            : $marketRepo->findActifs();
        $filters['allowedMarkets'] = array_map(
            static fn(Market $market): int => (int) $market->getId(),
            $allowedMarkets
        );

        // Zones avec coordonnées
        $zones = $zoneGeoRepo->findWithAlertCountFiltered($filters);

        // Enrichir avec la répartition par catégorie et corridors pour les popups
        $result = [];
        foreach ($zones as $zone) {
            $marketId = $zone['marketId'] ?? null;
            if (!$marketId) {
                continue;
            }

            // Répartition par catégorie pour ce marché
            $catStats = $alertRepo->getCategorieStatsByMarket($marketId, $filters);

            // Répartition par corridor pour ce marché
            $corridorStats = $alertRepo->getCorridorStatsByMarket($marketId, $filters);

            $result[] = [
                'id'          => (int) $zone['id'],
                'nom'         => $zone['nom'] ?? '',
                'codeIso3'    => $zone['codeIso3'] ?? '',
                'latitude'    => (float) $zone['latitude'],
                'longitude'   => (float) $zone['longitude'],
                'alertCount'  => (int) $zone['alertCount'],
                'maxPriority' => $zone['maxPriority'] ?? 'faible',
                'categories'  => $catStats,
                'corridors'   => $corridorStats,
            ];
        }

        return new JsonResponse($result);
    }

    /**
     * API — liste des alertes d'un marché (pour le panneau latéral au clic).
     */
    #[Route('/api/alertes', name: 'app_carte_alertes_api', methods: ['GET'])]
    public function apiAlertes(
        Request         $request,
        AlertRepository $alertRepo,
    ): JsonResponse {
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            return new JsonResponse([], 403);
        }

        $filters = [
            'market'     => $request->query->get('marketId'),
            'categorie'  => $request->query->get('categorie'),
            'priorite'   => $request->query->get('priorite'),
            'statut'     => $request->query->get('statut'),
            'urgence'    => $request->query->get('urgence'),
            'typeAlerte' => $request->query->get('typeAlerte'),
            'scoreMin'   => $request->query->get('scoreMin'),
            'scoreMax'   => $request->query->get('scoreMax'),
            'origine'    => $request->query->get('origine'),
        ];

        $alerts = $alertRepo->findForUser($user, $filters);

        return new JsonResponse(array_map(fn(Alert $a) => [
            'id'             => $a->getId(),
            'codeGei'        => $a->getCodeGei() ?? 'BROUILLON',
            'statut'         => $a->getStatut()?->value ?? 'nouveau',
            'statutLabel'    => $a->getStatut()?->label() ?? '—',
            'score'          => $a->getEffectiveScore(),
            'niveauPriorite' => $a->getEffectiveNiveauPriorite()?->value ?? 'faible',
            'prioriteLabel'  => $a->getEffectiveNiveauPriorite()?->label() ?? '—',
            'categorie'      => $a->getCategorie() ?? '',
            'categorieLabel' => $a->getCategorie() ?? '—',
            'dateCreation'   => $a->getDateCreation()->format('d/m/Y'),
            'resumeExecutif' => mb_substr($a->getResumeExecutif() ?? '', 0, 120),
            'portCorridor'   => $a->getPortCorridor() ?? '—',
        ], $alerts));
    }

    /**
     * API — itinéraires des alertes multi-marchés pour la couche cartographique.
     * Les compteurs de la carte restent basés sur le marché principal ; cette
     * route sert uniquement à visualiser les parcours associés.
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
        $allowedMarkets = $user->getRole() === \App\Enum\UserRoleEnum::PFT
            ? $user->getAllManagedMarkets()
            : $marketRepo->findActifs();
        $allowedIds = array_map(static fn(Market $market): int => (int) $market->getId(), $allowedMarkets);
        $mainIds = $selectedIds !== [] ? array_values(array_intersect($selectedIds, $allowedIds)) : $allowedIds;

        if ($mainIds === []) {
            return new JsonResponse([]);
        }

        $placeholders = implode(',', array_fill(0, count($mainIds), '?'));
        $rows = $em->getConnection()->fetchAllAssociative(
            "SELECT a.id,
                    principal.nom AS principal, principal.code_iso3 AS principal_iso3,
                    COALESCE(principal_zone.latitude, CASE principal.code_iso3
                        WHEN 'BEN' THEN 9.3077 WHEN 'TGO' THEN 8.6195 WHEN 'GHA' THEN 7.9465
                        WHEN 'BFA' THEN 12.2383 WHEN 'MLI' THEN 17.5707 WHEN 'NER' THEN 17.6078
                        WHEN 'SEN' THEN 14.4974 WHEN 'CIV' THEN 7.5400 ELSE 8.0000 END) AS principal_lat,
                    COALESCE(principal_zone.longitude, CASE principal.code_iso3
                        WHEN 'BEN' THEN 2.3158 WHEN 'TGO' THEN 0.8248 WHEN 'GHA' THEN -1.0232
                        WHEN 'BFA' THEN -1.5616 WHEN 'MLI' THEN -3.9962 WHEN 'NER' THEN 8.0817
                        WHEN 'SEN' THEN -14.4524 WHEN 'CIV' THEN -5.5471 ELSE 2.0000 END) AS principal_lng,
                    am.ordre, associe.nom AS associe, associe.code_iso3 AS associe_iso3,
                    associe_zone.latitude AS associe_lat, associe_zone.longitude AS associe_lng
             FROM alert a
             JOIN market principal ON principal.id = a.market_id
             LEFT JOIN zone_geo principal_zone ON principal_zone.market_id = principal.id
             JOIN alert_market am ON am.alert_id = a.id AND am.role = 'associe'
             JOIN market associe ON associe.id = am.market_id
             LEFT JOIN zone_geo associe_zone ON associe_zone.market_id = associe.id
             WHERE a.deleted_at IS NULL AND a.market_id IN ({$placeholders})
             ORDER BY a.id, am.ordre",
            $mainIds
        );

        $routes = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            if (!isset($routes[$id])) {
                $routes[$id] = [
                    'id' => $id,
                    'principal' => $row['principal'],
                    'points' => [[(float) $row['principal_lat'], (float) $row['principal_lng']]],
                    'markets' => [$row['principal']],
                ];
            }
            if ($row['associe_lat'] !== null && $row['associe_lng'] !== null) {
                $routes[$id]['points'][] = [(float) $row['associe_lat'], (float) $row['associe_lng']];
                $routes[$id]['markets'][] = $row['associe'];
            }
        }

        return new JsonResponse(array_values($routes));
    }
}
