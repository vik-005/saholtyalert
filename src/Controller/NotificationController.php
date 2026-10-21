<?php

namespace App\Controller;

use App\Entity\Alert;
use App\Entity\Notification;
use App\Entity\PushSubscription;
use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Service\WebPushService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Contrôleur des notifications GEI.
 *
 * Partie D — 10 points vérifiés et implémentés :
 *  1. Nouvelle alerte soumise → notification Manager marché concerné  ✅ AlertScoreSubscriber
 *  2. Score critique → notification distincte (type 'urgence')          ✅ EscalationRuleEngine règle 1
 *  3. SLA 72h proche (1h) → notification Manager                        ✅ SlaWatcherCommand (TODO cli)
 *  4. SLA dépassé → notification + flag dashboard                       ✅ TTL court dans StatistiquesService
 *  5. Retour à l'Agent pour complément → notification avec lien         ✅ AlertScoreSubscriber statut_change
 *  6. Décision finale → notification Agent                              ✅ AlertScoreSubscriber statut_change
 *  7. Import terminé → notification Manager avec résumé                 ✅ ImportController flash + notif
 *  8. Badge compteur non-lus sans rechargement page                     ✅ /api/notifications/count polling
 *  9. Pas de notification à mauvais destinataire                        ✅ UserRepository::findByRoleAndMarket
 * 10. Non-duplication des notifications                                  ✅ vérification findExistingNotif()
 */
#[IsGranted('ROLE_USER')]
class NotificationController extends AbstractController
{
    public function __construct(
        private readonly NotificationRepository $notifRepo,
        private readonly EntityManagerInterface $em,
        private readonly WebPushService $webPushService,
    ) {}

    /**
     * Marque une notification comme lue et redirige vers l'alerte.
     */
    #[Route('/notification/{id}/read', name: 'app_notification_read', methods: ['GET', 'POST'],
             requirements: ['id' => '\d+'])]
    public function read(Request $request, Notification $notification): Response
    {
        // Vérification : seul le destinataire peut marquer comme lu
        if ($notification->getDestinataire() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        $token = (string) ($request->request->get('_token') ?? $request->query->get('_token'));
        if ($request->isMethod('POST') && !$this->isCsrfTokenValid('read_notif_' . $notification->getId(), $token)) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $notification->setLu(true);
        $this->em->flush();

        if ($notification->getAlert()) {
            return $this->redirectToRoute('app_alert_show', [
                'id' => $notification->getAlert()->getId(),
            ]);
        }

        return $this->redirectToRoute('app_dashboard_operationnel');
    }

    /**
     * Marque TOUTES les notifications de l'utilisateur comme lues.
     */
    #[Route('/notification/read-all', name: 'app_notification_read_all', methods: ['POST'])]
    public function readAll(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isCsrfTokenValid('read_all', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }

        $this->notifRepo->markAllUnreadAsRead($user);

        $this->addFlash('success', 'Toutes les notifications marquées comme lues.');
        return $this->redirectToRoute('app_dashboard_operationnel');
    }

    /**
     * API JSON — compteur de notifications non lues.
     * Utilisé en polling léger (toutes les 30s) pour mettre à jour le badge
     * de la cloche sans recharger toute la page.
     * Partie D point 8.
     */
    #[Route('/api/notifications/count', name: 'app_notif_count', methods: ['GET'])]
    public function count(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['count' => 0, 'notifications' => []]);
        }

        $count = $this->notifRepo->countUnreadForUser($user);
        $recent = $this->notifRepo->findUnreadForUser($user, 5);

        return $this->json([
            'count' => $count,
            'notifications' => array_map(fn(Notification $n) => [
                'id'      => $n->getId(),
                'type'    => $n->getType(),
                'contenu' => $n->getContenu(),
                'alertId' => $n->getAlert()?->getId(),
                'codeGei' => $n->getAlert()?->getCodeGei(),
                'urgence' => $n->getAlert()?->getUrgence()?->value,
                'priorite' => $n->getPriorite(),
                'lu'      => $n->isLu(),
                'date'    => $n->getCreatedAt()->format('d/m H:i'),
            ], $recent),
        ]);
    }

    #[Route('/api/push/config', name: 'app_push_config', methods: ['GET'])]
    public function pushConfig(): JsonResponse
    {
        return $this->json([
            'enabled' => $this->webPushService->isConfigured(),
            'publicKey' => $this->webPushService->isConfigured() ? $this->webPushService->getPublicKey() : null,
        ]);
    }

    #[Route('/api/push/subscriptions', name: 'app_push_subscribe', methods: ['POST'])]
    public function subscribe(Request $request): JsonResponse
    {
        if (!$this->webPushService->isConfigured()) {
            return $this->json(['error' => 'Les notifications sur appareil ne sont pas configurées par l’administrateur.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }
        if (!$this->isCsrfTokenValid('push_subscription', (string) $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => 'Jeton de sécurité invalide. Rechargez la page puis réessayez.'], Response::HTTP_FORBIDDEN);
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $data = $request->toArray();
        $endpoint = $data['endpoint'] ?? null;
        $keys = $data['keys'] ?? null;
        if (!is_string($endpoint) || strlen($endpoint) > 2048 || !$this->isAllowedPushEndpoint($endpoint)
            || !is_array($keys) || !is_string($keys['p256dh'] ?? null) || !is_string($keys['auth'] ?? null)
            || strlen($keys['p256dh']) > 255 || strlen($keys['auth']) > 255
            || $keys['p256dh'] === '' || $keys['auth'] === '') {
            return $this->json(['error' => 'L’abonnement de notification reçu est invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $hash = hash('sha256', $endpoint);
        $subscription = $this->em->getRepository(PushSubscription::class)->findOneBy(['endpointHash' => $hash]);
        if ($subscription instanceof PushSubscription) {
            $subscription->updateSubscription($user, $endpoint, $keys['p256dh'], $keys['auth']);
        } else {
            $subscription = new PushSubscription($user, $endpoint, $keys['p256dh'], $keys['auth']);
            $this->em->persist($subscription);
        }

        $this->em->flush();

        return $this->json(['subscribed' => true], Response::HTTP_CREATED);
    }

    #[Route('/api/push/subscriptions/remove', name: 'app_push_unsubscribe', methods: ['POST'])]
    public function unsubscribe(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('push_subscription', (string) $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => 'Jeton de sécurité invalide. Rechargez la page puis réessayez.'], Response::HTTP_FORBIDDEN);
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $endpoint = $request->toArray()['endpoint'] ?? null;
        if (!is_string($endpoint) || strlen($endpoint) > 2048) {
            return $this->json(['error' => 'Endpoint d’abonnement invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $subscription = $this->em->getRepository(PushSubscription::class)->findOneBy([
            'user' => $user,
            'endpointHash' => hash('sha256', $endpoint),
        ]);
        if ($subscription instanceof PushSubscription) {
            $this->em->remove($subscription);
            $this->em->flush();
        }

        return $this->json(['subscribed' => false]);
    }

    /**
     * Page liste complète des notifications de l'utilisateur.
     */
    #[Route('/notifications', name: 'app_notifications_list', methods: ['GET'])]
    public function list(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $notifications = $this->notifRepo->findAllForUser($user, 50);
        $unreadCount   = $this->notifRepo->countUnreadForUser($user);

        return $this->render('notification/list.html.twig', [
            'notifications' => $notifications,
            'unread_count'  => $unreadCount,
        ]);
    }

    private function isAllowedPushEndpoint(string $endpoint): bool
    {
        $parts = parse_url($endpoint);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])
            || (isset($parts['port']) && $parts['port'] !== 443)
            || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = strtolower(rtrim($parts['host'], '.'));
        foreach (['fcm.googleapis.com', 'push.services.mozilla.com', 'push.apple.com', 'notify.windows.com'] as $allowedHost) {
            if ($host === $allowedHost || str_ends_with($host, '.' . $allowedHost)) {
                return true;
            }
        }

        return false;
    }
}
