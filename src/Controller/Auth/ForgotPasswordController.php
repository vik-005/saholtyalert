<?php

namespace App\Controller\Auth;

use App\Entity\AccessLog;
use App\Entity\User;
use App\Enum\UserRoleEnum;
use App\Repository\UserRepository;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ForgotPasswordController extends AbstractController
{
    #[Route('/mot-de-passe-oublie', name: 'app_forgot_password', methods: ['GET', 'POST'])]
    public function forgotPassword(
        Request $request,
        UserRepository $userRepository,
        NotificationService $notificationService,
        EntityManagerInterface $em,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard_operationnel');
        }

        $submitted = false;
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('forgot_password', (string) $request->request->get('_csrf_token'))) {
                $error = 'Jeton de sécurité invalide. Veuillez réessayer.';
            } else {
                $email = strtolower(trim((string) $request->request->get('email', '')));

                if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $error = 'Veuillez saisir une adresse email valide.';
                } else {
                    $submitted = true;
                    $user = $userRepository->findOneBy(['email' => $email]);

                    if ($user instanceof User) {
                        // Journalisation sécurisée de la demande (traçabilité sans fuite)
                        $log = new AccessLog();
                        $log->setUser($user);
                        $log->setAction('demande_reinitialisation');
                        $log->setIpAdresse($request->getClientIp());
                        $log->setUserAgent($request->headers->get('User-Agent'));
                        $log->setResultat('success');
                        $log->setDetails(sprintf('Demande de réinitialisation de mot de passe initiée pour %s (%s)', $user->getNomComplet(), $user->getEmail()));
                        $em->persist($log);
                        $em->flush();

                        // Notification interne au Manager ou aux Administrateurs
                        $targetManager = $user->getCreatedByManager();
                        if ($targetManager instanceof User) {
                            $notificationService->notify(
                                destinataire: $targetManager,
                                contenu: sprintf('L’agent %s (%s) a signalé l’oubli de son mot de passe. Vous pouvez réinitialiser son accès dans Gestion des Utilisateurs.', $user->getNomComplet(), $user->getEmail()),
                                type: 'info',
                                priorite: 1,
                            );
                        } else {
                            // Si pas de manager rattaché (ex. Manager ou Superadmin), notifier les Superadmins
                            $superadmins = $userRepository->findByRole(UserRoleEnum::SUPERADMIN->value);
                            foreach ($superadmins as $admin) {
                                $notificationService->notify(
                                    destinataire: $admin,
                                    contenu: sprintf('Demande de réinitialisation de mot de passe pour %s (%s).', $user->getNomComplet(), $user->getEmail()),
                                    type: 'info',
                                    priorite: 1,
                                );
                            }
                        }
                    }
                }
            }
        }

        return $this->render('auth/forgot_password.html.twig', [
            'submitted' => $submitted,
            'error'     => $error,
        ]);
    }
}
