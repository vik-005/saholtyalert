<?php

namespace App\Controller\Auth;

use App\Entity\AccessLog;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\PasswordResetManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

final class ForgotPasswordController extends AbstractController
{
    #[Route('/mot-de-passe-oublie', name: 'app_forgot_password', methods: ['GET', 'POST'])]
    public function forgotPassword(
        Request $request,
        UserRepository $userRepository,
        EntityManagerInterface $em,
        PasswordResetManager $passwordResetManager,
        MailerInterface $mailer,
        #[Target('password_reset.limiter')] RateLimiterFactory $passwordResetLimiter,
        #[Autowire('%app.base_url%')] string $baseUrl,
        #[Autowire('%app.mailer_from%')] string $mailerFrom,
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
                $limiter = $passwordResetLimiter->create($request->getClientIp() ?? 'unknown');
                if (!$limiter->consume()->isAccepted()) {
                    return $this->render('auth/forgot_password.html.twig', [
                        'submitted' => true,
                        'error' => null,
                    ]);
                }

                $email = strtolower(trim((string) $request->request->get('email', '')));
                $submitted = true;

                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $user = $userRepository->findOneBy(['email' => $email]);
                    if ($user instanceof User && $user->isActif()) {
                        $token = $passwordResetManager->issue($user);
                        $resetUrl = rtrim($baseUrl, '/') . $this->generateUrl('app_reset_password', ['token' => $token]);
                        $emailMessage = (new TemplatedEmail())
                            ->from(new Address($mailerFrom, 'Plateforme GEI'))
                            ->to($user->getEmail())
                            ->subject('Réinitialisation de votre mot de passe GEI')
                            ->htmlTemplate('emails/password_reset.html.twig')
                            ->context(['resetUrl' => $resetUrl]);
                        $mailer->send($emailMessage);

                        $log = new AccessLog();
                        $log->setUser($user);
                        $log->setAction('password_reset_requested');
                        $log->setIpAdresse($request->getClientIp());
                        $log->setUserAgent($request->headers->get('User-Agent'));
                        $log->setResultat('success');
                        $log->setDetails('Demande de réinitialisation envoyée.');
                        $em->persist($log);
                        $em->flush();
                    }
                }
            }
        }

        return $this->render('auth/forgot_password.html.twig', [
            'submitted' => $submitted,
            'error' => $error,
        ]);
    }

    #[Route('/reinitialiser-mot-de-passe/{token}', name: 'app_reset_password', methods: ['GET', 'POST'])]
    public function resetPassword(
        string $token,
        Request $request,
        PasswordResetManager $passwordResetManager,
    ): Response {
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('reset_password', (string) $request->request->get('_csrf_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            $password = (string) $request->request->get('password');
            $confirmation = (string) $request->request->get('password_confirm');
            if (strlen($password) < 12 || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/\d/', $password)) {
                $error = 'Choisissez un mot de passe d’au moins 12 caractères avec majuscule, minuscule et chiffre.';
            } elseif ($password !== $confirmation) {
                $error = 'La confirmation du mot de passe ne correspond pas.';
            } elseif (!$passwordResetManager->reset($token, $password)) {
                $error = 'Ce lien est invalide ou expiré. Demandez une nouvelle réinitialisation.';
            } else {
                $this->addFlash('success', 'Votre mot de passe a été modifié. Vous pouvez vous connecter.');
                return $this->redirectToRoute('app_login');
            }
        }

        return $this->render('auth/reset_password.html.twig', [
            'token' => $token,
            'error' => $error,
        ]);
    }
}