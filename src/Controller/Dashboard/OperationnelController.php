<?php

namespace App\Controller\Dashboard;

use App\Entity\User;
use App\Repository\AlertRepository;
use App\Repository\MarketRepository;
use App\Repository\Urgence72hCaseRepository;
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
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        // ── Filtres ──────────────────────────────────────────────────────────
        $filters = [
            'statut'          => $request->query->get('statut'),
            'niveauPriorite'  => $request->query->get('priorite'),
            'market'          => $request->query->get('market'),
            'search'          => $request->query->get('search'),
            'categorie'       => $request->query->get('categorie'),
            'urgence'         => $request->query->get('urgence'),
        ];

        // ── Données principales ──────────────────────────────────────────────
        $alerts      = $alertRepository->findForUser($user, $filters);
        $activeCases = $urgenceRepo->findActiveCases();
        $markets     = $marketRepository->findBy(['actif' => true]);

        // ── KPI enrichis ────────────────────────────────────────────────────
        $kpisBase = $alertRepository->getKpiStats();
        $kpisByPrio = $alertRepository->countByNiveauPriorite();
        $kpisByPays = $alertRepository->countByPays();
        $scoreMoyen = $alertRepository->getScoreMoyen();

        $kpis = array_merge($kpisBase, [
            'critique' => $kpisByPrio['critique'] ?? 0,
            'eleve'    => $kpisByPrio['eleve']    ?? 0,
            'modere'   => $kpisByPrio['modere']   ?? 0,
            'faible'   => $kpisByPrio['faible']   ?? 0,
            'parPays'  => $kpisByPays,
            'scoreMoyen' => $scoreMoyen,
        ]);

        // ── Données graphique évolution (30 derniers jours) ─────────────────
        $chartData = $alertRepository->getEvolutionData(30);

        return $this->render('dashboard/operationnel.html.twig', [
            'alerts'       => $alerts,
            'kpis'         => $kpis,
            'active_cases' => $activeCases,
            'markets'      => $markets,
            'filters'      => $filters,
            'chartLabels'  => $chartData['labels'],
            'chartNouvelles'  => $chartData['nouvelles'],
            'chartTransmises' => $chartData['transmises'],
        ]);
    }
}
