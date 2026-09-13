<?php

namespace App\Controller\Dashboard;

use App\Dto\CorridorFilterDTO;
use App\Entity\User;
use App\Service\StatistiquesService;
use App\Repository\MarketRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class CorridorsController extends AbstractController
{
    #[Route('/corridors', name: 'app_dashboard_corridors')]
    public function index(
        Request             $request,
        StatistiquesService $stats,
        MarketRepository    $marketRepo,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        // Tous les marchés actifs (nécessaire pour le DTO et l'affichage des filtres)
        $allMarkets = $marketRepo->findBy(['actif' => true]);

        // Construire le DTO — normalise correctement markets[] ou markets=1,2
        // et résout le bug "Input value 'markets' contains a non-scalar value"
        $dto = CorridorFilterDTO::fromRequest($request, $allMarkets);

        // Récupérer toutes les statistiques via la méthode centralisée
        $dashboard = $stats->getCorridorDashboard($dto);

        return $this->render('dashboard/corridors.html.twig', [
            // Filtres
            'debut'            => $dto->dateDebut,
            'fin'              => $dto->dateFin,
            'selected_markets' => $dto->marketIds,
            'all_markets'      => $allMarkets,
            'type_loc'         => $dto->typeLoc ?? '',
            // Labels dynamiques (calculés dans le DTO, pas dans Twig)
            'page_title'       => $dto->pageTitle(),
            'page_subtitle'    => $dto->pageSubtitle(),
            'chart_title'      => $dto->chartTitle(),
            'chart_subtitle'   => $dto->chartSubtitle(),
            'chart_item_label' => $dto->itemLabel(),
            'type_icon'        => $dto->typeIcon(),
            // Données statistiques (toutes préparées côté service)
            'top_localisations' => $dashboard['top_localisations'],
            'volume_par_pays'   => $dashboard['volume_par_pays'],
            'loc_type_stats'    => $dashboard['loc_type_stats'],
            'kpis'              => $dashboard['kpis'],
            'has_data'          => $dashboard['has_data'],
        ]);
    }
}
