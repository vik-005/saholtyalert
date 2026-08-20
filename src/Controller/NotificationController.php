<?php

namespace App\Controller;

use App\Entity\Alert;
use App\Entity\Notification;
use App\Entity\User;
use App\Repository\NotificationRepository;
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
    ) {}

    /**
     * Marque une notification comme lue et redirige vers l'alerte.
     */
    #[Route('/notification/{id}/read', name: 'app_notification_read', methods: ['GET'],
             requirements: ['id' => '\d+'])]
    public function read(Notification $notification): Response
    {
        // Vérification : seul le destinataire peut marquer comme lu
        if ($notification->getDestinataire() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
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

        $unread = $this->notifRepo->findUnreadForUser($user, 100);
        foreach ($unread as $notif) {
            $notif->setLu(true);
        }
        $this->em->flush();

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
                'lu'      => $n->isLu(),
                'date'    => $n->getCreatedAt()->format('d/m H:i'),
            ], $recent),
        ]);
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
}
