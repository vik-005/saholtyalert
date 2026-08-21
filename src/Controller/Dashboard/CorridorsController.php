<?php

namespace App\Controller\Dashboard;

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
        $fin   = $request->query->get('fin',   (new \DateTime())->format('Y-m-d'));
        $debut = $request->query->get('debut', (new \DateTime('-90 days'))->format('Y-m-d'));

        $allMarkets = $marketRepo->findBy(['actif' => true]);
        $marketIds  = array_filter(
            explode(',', $request->query->get('markets', '')),
            fn($v) => is_numeric($v)
        );
        $marketIds = array_map('intval', $marketIds);

        $topCorridors  = $stats->getTopCorridors($debut, $fin, $marketIds, 20);
        $volumeParPays = $stats->getVolumeParPays($debut, $fin, $marketIds);

        return $this->render('dashboard/corridors.html.twig', [
            'debut'          => $debut,
            'fin'            => $fin,
            'selected_markets' => $marketIds,
            'all_markets'    => $allMarkets,
            'top_corridors'  => $topCorridors,
            'volume_par_pays'=> $volumeParPays,
        ]);
    }
}
