<?php

namespace App\Controller\Geo;

use App\Repository\ListeReferenceValeurRepository;
use App\Repository\MarketRepository;
use App\Security\GeoVoter;
use App\Service\Geo\GeoChoroplethService;
use App\Service\Geo\GeoFlowService;
use App\Service\Geo\GeoHeatmapService;
use App\Service\Geo\GeoStatisticsService;
use App\Service\Geo\GeoWorldMapService;
use App\Enum\AlertStatut;
use App\Enum\NiveauPriorite;
use App\Enum\TypeAlerte;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Module géospatial GEI — GEOINT Center.
 *
 * Triple protection :
 *   1. access_control Symfony (security.yaml) → ROLE_PFT minimum
 *   2. #[IsGranted('ROLE_PFT')] sur la classe
 *   3. GeoVoter::denyAccessUnlessGranted sur chaque action
 *
 * Pages HTML :
 *   GET /geo/               → tableau de bord géospatial
 *   GET /geo/choropleth     → carte choroplèthe ECharts
 *   GET /geo/heatmap        → carte thermique MapLibre
 *   GET /geo/flux           → carte des flux MapLibre
 *   GET /geo/carte-mondiale → carte mondiale détaillée MapLibre
 *
 * API JSON (GeoJSON / JSON) :
 *   GET /geo/api/dashboard  → KPIs + stats
 *   GET /geo/api/choropleth → série ECharts
 *   GET /geo/api/heatmap    → GeoJSON heatmap
 *   GET /geo/api/flux       → GeoJSON flux LineString
 *   GET /geo/api/worldmap   → GeoJSON points/clusters
 *   GET /geo/api/stats      → stats sidebar légères
 */
#[Route('/geo')]
#[IsGranted('ROLE_PFT')]
class GeoController extends AbstractController
{
    public function __construct(
        private readonly GeoChoroplethService          $choropleth,
        private readonly GeoHeatmapService             $heatmap,
        private readonly GeoFlowService                $flow,
        private readonly GeoStatisticsService          $statistics,
        private readonly GeoWorldMapService            $worldMap,
        private readonly MarketRepository              $marketRepo,
        private readonly ListeReferenceValeurRepository $listeRefRepo,
    ) {}

    // ══════════════════════════════════════════════════════════════════════
    // Pages HTML
    // ══════════════════════════════════════════════════════════════════════

    #[Route('/', name: 'app_geo_dashboard', methods: ['GET'])]
    public function dashboard(): Response
    {
        $this->denyAccessUnlessGranted(GeoVoter::GEO_VIEW);

        return $this->render('geo/dashboard.html.twig', [
            'markets'    => $this->marketRepo->findActifs(),
            'categories' => $this->listeRefRepo->findActivesByType('categorie'),
            'statuts'    => AlertStatut::cases(),
            'niveaux'    => NiveauPriorite::cases(),
            'typeAlertes'=> TypeAlerte::cases(),
            'continents' => $this->getContinentList(),
        ]);
    }

    #[Route('/choropleth', name: 'app_geo_choropleth', methods: ['GET'])]
    public function choroplethPage(): Response
    {
        $this->denyAccessUnlessGranted(GeoVoter::GEO_VIEW);

        return $this->render('geo/choropleth.html.twig', [
            'markets'    => $this->marketRepo->findActifs(),
            'categories' => $this->listeRefRepo->findActivesByType('categorie'),
            'statuts'    => AlertStatut::cases(),
            'niveaux'    => NiveauPriorite::cases(),
            'continents' => $this->getContinentList(),
        ]);
    }

    #[Route('/heatmap', name: 'app_geo_heatmap', methods: ['GET'])]
    public function heatmapPage(): Response
    {
        $this->denyAccessUnlessGranted(GeoVoter::GEO_VIEW);

        return $this->render('geo/heatmap.html.twig', [
            'markets'    => $this->marketRepo->findActifs(),
            'categories' => $this->listeRefRepo->findActivesByType('categorie'),
            'statuts'    => AlertStatut::cases(),
            'niveaux'    => NiveauPriorite::cases(),
        ]);
    }

    #[Route('/flux', name: 'app_geo_flux', methods: ['GET'])]
    public function fluxPage(): Response
    {
        $this->denyAccessUnlessGranted(GeoVoter::GEO_VIEW);

        return $this->render('geo/flux.html.twig', [
            'markets'    => $this->marketRepo->findActifs(),
            'categories' => $this->listeRefRepo->findActivesByType('categorie'),
            'statuts'    => AlertStatut::cases(),
            'niveaux'    => NiveauPriorite::cases(),
        ]);
    }

    #[Route('/carte-mondiale', name: 'app_geo_worldmap', methods: ['GET'])]
    public function worldMapPage(): Response
    {
        $this->denyAccessUnlessGranted(GeoVoter::GEO_VIEW);

        return $this->render('geo/worldmap.html.twig', [
            'markets'    => $this->marketRepo->findActifs(),
            'categories' => $this->listeRefRepo->findActivesByType('categorie'),
            'statuts'    => AlertStatut::cases(),
            'niveaux'    => NiveauPriorite::cases(),
            'typeAlertes'=> TypeAlerte::cases(),
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // API JSON / GeoJSON
    // ══════════════════════════════════════════════════════════════════════

    /** KPIs + stats complètes pour le tableau de bord géospatial. */
    #[Route('/api/dashboard', name: 'app_geo_api_dashboard', methods: ['GET'])]
    public function apiDashboard(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(GeoVoter::GEO_API);
        return $this->json($this->statistics->getDashboardData($this->extractFilters($request)));
    }

    /**
     * Données série ECharts pour la choroplèthe.
     * ?level=world (défaut) ou ?level=continent
     */
    #[Route('/api/choropleth', name: 'app_geo_api_choropleth', methods: ['GET'])]
    public function apiChoropleth(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(GeoVoter::GEO_API);
        $filters = $this->extractFilters($request);
        $level   = $request->query->get('level', 'world');

        $data = $level === 'continent'
            ? $this->choropleth->getContinentChoropleth($filters)
            : $this->choropleth->getWorldChoropleth($filters);

        return $this->json($data);
    }

    /**
     * GeoJSON FeatureCollection pour la heatmap MapLibre.
     * ?mode=density (défaut) ou ?mode=score
     * ?limit=2000 (défaut, max 3000)
     */
    #[Route('/api/heatmap', name: 'app_geo_api_heatmap', methods: ['GET'])]
    public function apiHeatmap(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(GeoVoter::GEO_API);
        $filters = $this->extractFilters($request);
        $mode    = $request->query->get('mode', GeoHeatmapService::MODE_DENSITY);
        $limit   = min(3000, max(100, $request->query->getInt('limit', 2000)));

        if (!in_array($mode, [GeoHeatmapService::MODE_DENSITY, GeoHeatmapService::MODE_SCORE], true)) {
            $mode = GeoHeatmapService::MODE_DENSITY;
        }

        return $this->json($this->heatmap->getHeatmapGeoJson($filters, $mode, $limit));
    }

    /**
     * GeoJSON pour la carte des flux.
     * Retourne { routes: FeatureCollection, locations: FeatureCollection }
     */
    #[Route('/api/flux', name: 'app_geo_api_flux', methods: ['GET'])]
    public function apiFlux(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(GeoVoter::GEO_API);
        $filters = $this->extractFilters($request);

        return $this->json([
            'routes'    => $this->flow->getFlowGeoJson($filters),
            'locations' => $this->flow->getLocationPoints($filters),
        ]);
    }

    /**
     * GeoJSON pour la carte mondiale détaillée.
     * ?layer=alerts (défaut) | infrastructure | markets
     */
    #[Route('/api/worldmap', name: 'app_geo_api_worldmap', methods: ['GET'])]
    public function apiWorldMap(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(GeoVoter::GEO_API);
        $filters = $this->extractFilters($request);
        $layer   = $request->query->get('layer', 'alerts');
        $limit   = min(3000, max(100, $request->query->getInt('limit', 2000)));

        $data = match ($layer) {
            'infrastructure' => $this->worldMap->getInfrastructureLayer($filters),
            'markets'        => $this->worldMap->getMarketsLayer($filters),
            default          => $this->worldMap->getAlertsLayer($filters, $limit),
        };

        return $this->json($data);
    }

    /** Stats légères pour le panneau latéral (appelé fréquemment). */
    #[Route('/api/stats', name: 'app_geo_api_stats', methods: ['GET'])]
    public function apiStats(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(GeoVoter::GEO_API);
        return $this->json($this->statistics->getSidebarStats($this->extractFilters($request)));
    }

    // ══════════════════════════════════════════════════════════════════════
    // Helpers privés
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Extrait et nettoie les filtres depuis la requête HTTP.
     * Valide les types — ne fait jamais confiance au client.
     */
    private function extractFilters(Request $request): array
    {
        $filters = [];

        foreach (['statut','categorie','priorite','typeAlerte','typeLocalisation','continent','dateDebut','dateFin'] as $p) {
            $v = $request->query->get($p);
            if ($v !== null && $v !== '') {
                $filters[$p] = htmlspecialchars(strip_tags(trim($v)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        foreach (['scoreMin','scoreMax'] as $p) {
            $v = $request->query->get($p);
            if ($v !== null && $v !== '' && is_numeric($v)) {
                $filters[$p] = (int) $v;
            }
        }

        // markets[] : tableau d'IDs entiers
        $marketRaw = $request->query->all('markets');
        if (!empty($marketRaw)) {
            $ids = array_values(array_unique(array_filter(array_map('intval', (array) $marketRaw))));
            if ($ids !== []) {
                $filters['markets'] = $ids;
            }
        }

        return $filters;
    }

    private function getContinentList(): array
    {
        return [
            "Afrique de l'Ouest",
            'Afrique Centrale',
            "Afrique de l'Est",
            'Afrique du Nord',
            'Afrique Australe',
            'Afrique',
        ];
    }
}
