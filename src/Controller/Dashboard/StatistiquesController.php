<?php

namespace App\Controller\Dashboard;

use App\Dto\AlertFilterDTO;
use App\Entity\User;
use App\Repository\MarketRepository;
use App\Service\AlertFilterService;
use App\Service\StatistiquesService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class StatistiquesController extends AbstractController
{
    #[Route('/statistiques', name: 'app_dashboard_statistiques', methods: ['GET'])]
    public function index(
        Request              $request,
        StatistiquesService  $stats,
        MarketRepository     $marketRepo,
        AlertFilterService   $filterService,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        // --- Filtres globalisés avec AlertFilterDTO ---
        $debut = $request->query->get('debut', (new \DateTime('-30 days'))->format('Y-m-d'));
        $fin   = $request->query->get('fin', (new \DateTime())->format('Y-m-d'));

        // Fix: utiliser query->all() pour éviter "non-scalar value" avec markets[]
        $allQ      = $request->query->all();
        $marketsRaw = $allQ['markets'] ?? null;
        if (is_array($marketsRaw)) {
            $marketIds = array_values(array_filter(array_map('intval', $marketsRaw)));
        } elseif (is_string($marketsRaw) && $marketsRaw !== '') {
            $marketIds = array_values(array_filter(array_map('intval', explode(',', $marketsRaw))));
        } else {
            $marketIds = [];
        }

        // Restreindre aux marchés gérés si Manager
        $allMarkets = $marketRepo->findBy(['actif' => true]);
        if ($user->getRole() === \App\Enum\UserRoleEnum::PFT) {
            $allowedMarketIds = array_map(fn($m) => $m->getId(), $user->getAllManagedMarkets() ?? []);
            $marketIds = $marketIds === [] ? $allowedMarketIds : array_values(array_intersect($marketIds, $allowedMarketIds));
        }

        // --- Filtre DTO pour compatibilité avec AlertFilterService ---
        $filterDto = new AlertFilterDTO();
        $filterDto->market = $marketIds ? (int) reset($marketIds) : null;
        $filterDto->dateDebut = $debut;
        $filterDto->dateFin = $fin;
        $filterDto->page = 1;
        $filterDto->limit = 1000; // Pas de pagination pour les stats globales

        return $this->render('dashboard/statistiques.html.twig', [
            // Filtres actifs
            'debut'          => $debut,
            'fin'            => $fin,
            'selected_markets' => $marketIds,
            'all_markets'    => $allMarkets,

            // A.1 — Par pays
            'volume_par_pays'    => $stats->getVolumeParPays($debut, $fin, $marketIds),
            'score_moyen_pays'   => $stats->getScoreMoyenParPays($debut, $fin, $marketIds),
            'statuts_par_pays'   => $stats->getStatutsParPays($debut, $fin, $marketIds),
            'tx_transmission'    => $stats->getTauxTransmissionParPays($debut, $fin, $marketIds),

            // A.2 — Jauges
            'jauge_sla'          => $stats->getTauxSla($debut, $fin),
            'jauge_exploitables' => $stats->getTauxExploitables($debut, $fin, $marketIds),

            // A.3 — Autres
            'repartition_categorie' => $stats->getRepartitionCategorie($debut, $fin, $marketIds),
            'top_corridors'         => $stats->getTopCorridors($debut, $fin, $marketIds),
            'top_operateurs'        => $stats->getTopOperateurs($debut, $fin, $marketIds),
            'heatmap'               => $stats->getHeatmapFiabiliteCredibilite($debut, $fin, $marketIds),
            'activite_agents'       => $stats->getActiviteParAgent($debut, $fin, $marketIds),
            'delai_moyen'           => $stats->getDelaiMoyenTraitement($debut, $fin, $marketIds),
            'evolution'             => $stats->getEvolutionTemporelle($debut, $fin, $marketIds),
        ]);
    }

    /**
     * API JSON pour rechargement AJAX des graphiques quand les filtres changent.
     */
    #[Route('/statistiques/data', name: 'app_statistiques_data', methods: ['GET'])]
    public function data(Request $request, StatistiquesService $stats): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        $debut     = $request->query->get('debut', (new \DateTime('-30 days'))->format('Y-m-d'));
        $fin       = $request->query->get('fin',   (new \DateTime())->format('Y-m-d'));
        $allQ2       = $request->query->all();
        $marketsRaw2 = $allQ2['markets'] ?? null;
        if (is_array($marketsRaw2)) {
            $marketIds = array_values(array_filter(array_map('intval', $marketsRaw2)));
        } elseif (is_string($marketsRaw2) && $marketsRaw2 !== '') {
            $marketIds = array_values(array_filter(array_map('intval', explode(',', $marketsRaw2))));
        } else {
            $marketIds = [];
        }
        if ($user->getRole() === \App\Enum\UserRoleEnum::PFT) {
            $allowedMarketIds = array_map(fn($m) => $m->getId(), $user->getAllManagedMarkets() ?? []);
            $marketIds = $marketIds === [] ? $allowedMarketIds : array_values(array_intersect($marketIds, $allowedMarketIds));
        }

        return $this->json([
            'volume_par_pays'       => $stats->getVolumeParPays($debut, $fin, $marketIds),
            'score_moyen_pays'      => $stats->getScoreMoyenParPays($debut, $fin, $marketIds),
            'statuts_par_pays'      => $stats->getStatutsParPays($debut, $fin, $marketIds),
            'tx_transmission'       => $stats->getTauxTransmissionParPays($debut, $fin, $marketIds),
            'jauge_sla'             => $stats->getTauxSla($debut, $fin),
            'jauge_exploitables'    => $stats->getTauxExploitables($debut, $fin, $marketIds),
            'repartition_categorie' => $stats->getRepartitionCategorie($debut, $fin, $marketIds),
            'top_corridors'         => $stats->getTopCorridors($debut, $fin, $marketIds),
            'top_operateurs'        => $stats->getTopOperateurs($debut, $fin, $marketIds),
            'activite_agents'       => $stats->getActiviteParAgent($debut, $fin, $marketIds),
            'delai_moyen'           => $stats->getDelaiMoyenTraitement($debut, $fin, $marketIds),
            'evolution'             => $stats->getEvolutionTemporelle($debut, $fin, $marketIds),
        ]);
    }
}

