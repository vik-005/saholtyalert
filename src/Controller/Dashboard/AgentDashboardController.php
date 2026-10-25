<?php

namespace App\Controller\Dashboard;

use App\Entity\User;
use App\Enum\UserRoleEnum;
use App\Repository\AlertRepository;
use App\Repository\NotificationRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
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
 *
 * Améliorations v2 :
 *  - Consolidation : 1 requête SQL (getAgentKpiConsolide) remplace 8 appels séparés
 *  - Cache 1h sur les agrégations (invalidé si filtre de dates change)
 *  - Filtre temporel : ?days=30|90|365|all (défaut = null = tout)
 *  - KPIs enrichis : taux rejet, taux transmission en plus du taux validation
 *  - Sécurité : vérification ownership explicite + header Cache-Control no-store
 *  - Gestion d'erreurs : try/catch avec fallback sur données vides si DB indisponible
 */
#[IsGranted('ROLE_USER')]
class AgentDashboardController extends AbstractController
{
    /** Valeurs autorisées pour le filtre ?days= */
    private const ALLOWED_DAYS = [30, 90, 365];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/mon-tableau-de-bord', name: 'app_agent_dashboard', methods: ['GET'])]
    public function index(
        Request $request,
        AlertRepository $alertRepository,
        NotificationRepository $notificationRepo,
        CacheInterface $cache,
    ): Response {
        /** @var User|null $user */
        $user = $this->getUser();

        // ── Vérification ownership stricte ──────────────────────────────────
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        // Seuls les agents terrain ont ce dashboard ; les autres sont redirigés
        if ($user->getRole() !== UserRoleEnum::EMETTEUR_TERRAIN) {
            return $this->redirectToRoute(match ($user->getRole()) {
                UserRoleEnum::COMITE_AIT      => 'app_dashboard_kpi',
                UserRoleEnum::SECRETARIAT_GEI => 'app_alert_index',
                default                        => 'app_dashboard_operationnel',
            });
        }

        // ── Filtre temporel ──────────────────────────────────────────────────
        $daysParam = $request->query->get('days', '');
        $days      = null; // null = toutes les données
        if ($daysParam !== '' && $daysParam !== 'all') {
            $daysInt = (int) $daysParam;
            if (in_array($daysInt, self::ALLOWED_DAYS, true)) {
                $days = $daysInt;
            }
        }

        // ── Clé de cache unique par agent + filtre ───────────────────────────
        $cacheKey = sprintf('agent_dashboard_%d_%s', $user->getId(), $days ?? 'all');

        // ── Agrégations cachées (1h) ─────────────────────────────────────────
        try {
            $cached = $cache->get($cacheKey, function (ItemInterface $item) use ($alertRepository, $user, $days) {
                $item->expiresAfter(3600);

                // 1 seule requête SQL pour tous les KPIs (vs 8 requêtes avant)
                $kpi = $alertRepository->getAgentKpiConsolide($user, $days);

                // 1 requête par dimension (catégories, urgences, évolution)
                $mois = $days !== null ? (int) ceil($days / 30) : 12;
                $catStats           = $alertRepository->countByCategorieForAgent($user, $days);
                $urgenceStats       = $alertRepository->countByUrgenceForAgent($user, $days);
                $evolutionMensuelle = $alertRepository->getEvolutionMensuelleAgent($user, max(1, $mois));

                return [
                    'kpi'                => $kpi,
                    'catStats'           => $catStats,
                    'urgenceStats'       => $urgenceStats,
                    'evolutionMensuelle' => $evolutionMensuelle,
                    'hasError'           => false,
                ];
            });
        } catch (\Throwable $e) {
            // Fallback gracieux si la DB est indisponible — affiche une page dégradée
            $this->logger->error('AgentDashboard: erreur chargement données agrégées', [
                'userId'    => $user->getId(),
                'exception' => $e->getMessage(),
            ]);

            $cached = $this->buildEmptyPayload();
            $cached['hasError'] = true;
        }

        // ── Alertes récentes — non mises en cache (données temps-réel) ───────
        try {
            $alertesRecentes = $alertRepository->findRecentByAgent($user, 10);
        } catch (\Throwable $e) {
            $this->logger->error('AgentDashboard: erreur chargement alertes récentes', [
                'userId'    => $user->getId(),
                'exception' => $e->getMessage(),
            ]);
            $alertesRecentes = [];
        }

        // ── Notifications non lues ────────────────────────────────────────────
        try {
            $notifications = $notificationRepo->findAllForUser($user, 5);
        } catch (\Throwable $e) {
            $notifications = [];
        }

        // ── Réponse avec headers de sécurité ─────────────────────────────────
        $response = $this->render('dashboard/agent.html.twig', array_merge($cached, [
            'alertesRecentes' => $alertesRecentes,
            'notifications'   => $notifications,
            'days'            => $days,
            'allowedDays'     => self::ALLOWED_DAYS,
        ]));

        // Empêche la mise en cache par le navigateur ou les proxies
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }

    /** Retourne un payload vide compatible avec le template (fallback erreur DB). */
    private function buildEmptyPayload(): array
    {
        return [
            'kpi' => [
                'total' => 0, 'validee' => 0, 'rejetee' => 0, 'en_cours' => 0,
                'nouveau' => 0, 'transmis' => 0, 'en_attente' => 0,
                'taux_validation' => 0.0, 'taux_rejet' => 0.0, 'taux_transmission' => 0.0,
                'score_moyen' => null, 'delai_moyen_h' => null,
                'score_faible' => 0, 'score_modere' => 0, 'score_eleve' => 0, 'score_critique' => 0,
                'critique' => 0, 'eleve' => 0, 'modere' => 0, 'faible' => 0,
            ],
            'catStats'           => [],
            'urgenceStats'       => [],
            'evolutionMensuelle' => ['labels' => [], 'soumises' => [], 'validees' => [], 'mois' => 12],
            'hasError'           => false,
        ];
    }
}
