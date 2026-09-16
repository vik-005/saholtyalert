<?php

namespace App\Controller\Dashboard;

use App\Dto\AlertFilterDTO;
use App\Entity\User;
use App\Repository\AlertRepository;
use App\Repository\NotificationRepository;
use App\Repository\MarketRepository;
use App\Service\AlertFilterService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class GeneralDashboardController extends AbstractController
{
    #[Route('/dashboard/general', name: 'app_dashboard_general')]
    public function index(
        Request $request,
        NotificationRepository $notificationRepo,
        AlertRepository $alertRepository,
        MarketRepository $marketRepository,
        AlertFilterService $filterService,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        // Top 15 notifications (lues + non lues)
        $notifications = $notificationRepo->findAllForUser($user, 15);

        // --- KPIs via KPIService (avec cache) ---
        $kpis = $alertRepository->getKpiStats($user);  // filtré par rôle
        $kpisByPrio = $alertRepository->countByNiveauPriorite($user);
        $kpisByPays = $alertRepository->countByPays($user);
        $scoreMoyen = $alertRepository->getScoreMoyen($user);
        $delaiMoyenH = $alertRepository->getAvgDelaiTraitementHeures($user);

        // --- Données graphique évolution (30 derniers jours) ---
        $chartData = $alertRepository->getEvolutionData(30, $user);

        // --- Marchés actifs ---
        $markets = $marketRepository->findBy(['actif' => true]);

        // --- Filtres multi-critères ---
        $market = $request->query->get('market');
        if (is_array($market)) {
            $market = reset($market);
        }

        $filterDto = new AlertFilterDTO();
        $filterDto->statut = $request->query->get('statut');
        $filterDto->niveauPriorite = $request->query->get('priorite');
        $filterDto->market = $market ? (int) $market : null;
        $filterDto->search = $request->query->get('search');
        $filterDto->categorie = $request->query->get('categorie');
        $filterDto->urgence = $request->query->get('urgence');
        $filterDto->origine = $request->query->get('origine');
        $filterDto->dateDebut = $request->query->get('dateDebut');
        $filterDto->dateFin = $request->query->get('dateFin');
        $filterDto->agent = $request->query->get('agent') ? (int) $request->query->get('agent') : null;
        $filterDto->manager = $request->query->get('manager') ? (int) $request->query->get('manager') : null;
        $filterDto->tri = $request->query->get('tri');
        $filterDto->triSens = $request->query->get('triSens');
        $filterDto->page = max(1, (int) $request->query->get('page', 1));
        $filterDto->limit = 10;

        // --- Récupération des alertes avec QueryBuilder ---
        $qb = $filterService->buildCompleteQueryBuilder($filterDto, $user);
        $alerts = $qb->getQuery()->getResult();
        $totalAlerts = $filterService->countAlerts($filterDto, $user);
        $totalPages = max(1, (int) ceil($totalAlerts / $filterDto->limit));
        if ($filterDto->page > $totalPages) {
            $filterDto->page = $totalPages;
            $qb = $filterService->buildCompleteQueryBuilder($filterDto, $user);
            $alerts = $qb->getQuery()->getResult();
        }

        // --- KPIs enrichis (pour compatibilité templates) ---
        $kpis = array_merge($kpis, [
            'critique'    => $kpisByPrio['critique'] ?? 0,
            'eleve'       => $kpisByPrio['eleve']    ?? 0,
            'modere'      => $kpisByPrio['modere']   ?? 0,
            'faible'      => $kpisByPrio['faible']   ?? 0,
            'parPays'     => $kpisByPays,
            'scoreMoyen'  => $scoreMoyen,
            'delaiMoyenH' => $delaiMoyenH,
        ]);

        return $this->render('dashboard/general.html.twig', [
            'notifications' => $notifications,
            'kpis'          => $kpis,
            'markets'       => $markets,
            'chartLabels'   => $chartData['labels'],
            'chartNouvelles' => $chartData['nouvelles'],
            'chartTransmises' => $chartData['transmises'],
            'alerts'        => $alerts,
            'filters'       => (array) $filterDto,
            'page'          => $filterDto->page,
            'totalPages'    => $totalPages,
            'totalAlerts'   => $totalAlerts,
        ]);
    }
}
