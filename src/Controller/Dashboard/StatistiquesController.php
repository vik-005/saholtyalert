<?php

namespace App\Controller\Dashboard;

use App\Entity\User;
use App\Repository\MarketRepository;
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
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        // ── Filtres globaux ──────────────────────────────────────────────────
        $fin   = $request->query->get('fin',   (new \DateTime())->format('Y-m-d'));
        $debut = $request->query->get('debut', (new \DateTime('-30 days'))->format('Y-m-d'));

        $marketIds = array_filter(
            explode(',', $request->query->get('markets', '')),
            fn($v) => is_numeric($v)
        );
        $marketIds = array_map('intval', $marketIds);

        // Restreindre aux marchés gérés si Manager
        $allMarkets = $marketRepo->findBy(['actif' => true]);
        if ($user->getRole() === \App\Enum\UserRoleEnum::PFT && $marketIds === []) {
            $marketIds = array_map(fn($m) => $m->getId(), $user->getAllManagedMarkets() ?? []);
        }

        // ── Données pour tous les graphiques ────────────────────────────────
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
        $debut     = $request->query->get('debut', (new \DateTime('-30 days'))->format('Y-m-d'));
        $fin       = $request->query->get('fin',   (new \DateTime())->format('Y-m-d'));
        $marketIds = array_filter(explode(',', $request->query->get('markets', '')), 'is_numeric');
        $marketIds = array_map('intval', $marketIds);

        return $this->json([
            'volume_par_pays'       => $stats->getVolumeParPays($debut, $fin, $marketIds),
            'score_moyen_pays'      => $stats->getScoreMoyenParPays($debut, $fin, $marketIds),
            'statuts_par_pays'      => $stats->getStatutsParPays($debut, $fin, $marketIds),
            'tx_transmission'       => $stats->getTauxTransmissionParPays($debut, $fin, $marketIds),
            'jauge_sla'             => $stats->getTauxSla($debut, $fin),
            'jauge_exploitables'    => $stats->getTauxExploitables($debut, $fin, $marketIds),
            'repartition_categorie' => $stats->getRepartitionCategorie($debut, $fin, $marketIds),
            'top_corridors'         => $stats->getTopCorridors($debut, $fin, $marketIds),
            'activite_agents'       => $stats->getActiviteParAgent($debut, $fin, $marketIds),
            'delai_moyen'           => $stats->getDelaiMoyenTraitement($debut, $fin, $marketIds),
            'evolution'             => $stats->getEvolutionTemporelle($debut, $fin, $marketIds),
        ]);
    }

}
