<?php

namespace App\Controller\Dashboard;

use App\Dto\AlertFilterDTO;
use App\Entity\User;
use App\Repository\AlertRepository;
use App\Repository\MarketRepository;
use App\Repository\Urgence72hCaseRepository;
use App\Service\AlertFilterService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class OperationnelController extends AbstractController
{
    #[Route('/', name: 'app_dashboard_operationnel')]
    public function index(
        Request $request,
        AlertRepository $alertRepository,
        MarketRepository $marketRepository,
        Urgence72hCaseRepository $urgenceRepo,
        AlertFilterService $filterService,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        // --- Filtres multi-critères (normalisés) ---
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

        // --- Données principales ---
        $qb = $filterService->buildCompleteQueryBuilder($filterDto, $user);
        $alerts = $qb->getQuery()->getResult();
        $activeCases = $urgenceRepo->findActiveCases();
        $markets = $marketRepository->findBy(['actif' => true]);

        // --- KPI enrichis (centralisés, sans duplication rôle) ---
        $kpisBase = $alertRepository->getKpiStats($user);
        $kpisByPrio = $alertRepository->countByNiveauPriorite($user);
        $kpisByPays = $alertRepository->countByPays($user);
        $scoreMoyen = $alertRepository->getScoreMoyen($user);
        $chartData = $alertRepository->getEvolutionData(30, $user);

        $kpis = array_merge($kpisBase, [
            'critique' => $kpisByPrio['critique'] ?? 0,
            'eleve'    => $kpisByPrio['eleve']    ?? 0,
            'modere'   => $kpisByPrio['modere']   ?? 0,
            'faible'   => $kpisByPrio['faible']   ?? 0,
            'parPays'  => $kpisByPays,
            'scoreMoyen' => $scoreMoyen,
        ]);

        return $this->render('dashboard/operationnel.html.twig', [
            'alerts'       => $alerts,
            'kpis'         => $kpis,
            'active_cases' => $activeCases,
            'markets'      => $markets,
            'filters'      => (array) $filterDto,
            'chartLabels'  => $chartData['labels'],
            'chartNouvelles'  => $chartData['nouvelles'],
            'chartTransmises' => $chartData['transmises'],
        ]);
    }
}
