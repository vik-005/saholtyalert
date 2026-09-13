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
}
