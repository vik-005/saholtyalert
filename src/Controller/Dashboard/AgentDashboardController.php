<?php

namespace App\Controller\Dashboard;

use App\Entity\User;
use App\Enum\UserRoleEnum;
use App\Repository\AlertRepository;
use App\Repository\NotificationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Tableau de bord personnel des Agents (EMETTEUR_TERRAIN).
 *
 * Affiche uniquement les statistiques des alertes soumises PAR l'agent connecté,
 * sans aucune donnée globale ni cartographie. Conçu pour aider à la rédaction
 * de rapports d'activité terrain.
 */
#[IsGranted('ROLE_USER')]
class AgentDashboardController extends AbstractController
{
    #[Route('/mon-tableau-de-bord', name: 'app_agent_dashboard', methods: ['GET'])]
    public function index(
        AlertRepository $alertRepository,
        NotificationRepository $notificationRepo,
        CacheInterface $cache,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if ($user->getRole() !== UserRoleEnum::EMETTEUR_TERRAIN) {
            return $this->redirectToRoute(match ($user->getRole()) {
                UserRoleEnum::COMITE_AIT => 'app_dashboard_kpi',
                UserRoleEnum::SECRETARIAT_GEI => 'app_alert_index',
                default => 'app_dashboard_operationnel',
            });
        }

        $cached = $cache->get('agent_dashboard_' . $user->getId(), function (ItemInterface $item) use ($alertRepository, $user) {
            $item->expiresAfter(3600);

            $kpisBase      = $alertRepository->getKpiStats($user);
            $kpisByPrio    = $alertRepository->countByNiveauPriorite($user);
            $scoreMoyen    = $alertRepository->getScoreMoyenAgent($user);
            $delaiMoyenH   = $alertRepository->getAvgDelaiTraitementHeures($user);
            $tauxValidation    = $alertRepository->getTauxValidationAgent($user);
            $catStats          = $alertRepository->countByCategorieForAgent($user);
            $urgenceStats      = $alertRepository->countByUrgenceForAgent($user);
            $scoreDistribution = $alertRepository->getScoreDistributionAgent($user);
            $evolutionMensuelle = $alertRepository->getEvolutionMensuelleAgent($user);

            $kpis = array_merge($kpisBase, [
                'critique'  => $kpisByPrio['critique'] ?? 0,
                'eleve'     => $kpisByPrio['eleve']    ?? 0,
                'modere'    => $kpisByPrio['modere']   ?? 0,
                'faible'    => $kpisByPrio['faible']   ?? 0,
                'scoreMoyen'  => $scoreMoyen,
                'delaiMoyenH' => $delaiMoyenH,
            ]);

            return [
                'kpis'               => $kpis,
                'tauxValidation'     => $tauxValidation,
                'catStats'           => $catStats,
                'urgenceStats'       => $urgenceStats,
                'scoreDistribution'  => $scoreDistribution,
                'evolutionMensuelle' => $evolutionMensuelle,
            ];
        });

        $alertesRecentes = $alertRepository->findRecentByAgent($user, 8);
        $notifications = $notificationRepo->findAllForUser($user, 5);

        return $this->render('dashboard/agent.html.twig', array_merge($cached, [
            'alertesRecentes' => $alertesRecentes,
            'notifications'   => $notifications,
        ]));
    }
}
