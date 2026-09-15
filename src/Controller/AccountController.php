<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Color\Color;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/compte')]
#[IsGranted('ROLE_USER')]
final class AccountController extends AbstractController
{
    public function __construct(
        private readonly TotpAuthenticatorInterface $totpAuthenticator,
    ) {}

    #[Route('', name: 'app_account_settings', methods: ['GET', 'POST'])]
    public function settings(Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $hasher): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if ($request->isMethod('POST') && $request->request->get('action') === 'profile') {
            if (!$this->isCsrfTokenValid('account-profile', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
            }
            $prenom = trim((string) $request->request->get('prenom'));
            $nom = trim((string) $request->request->get('nom'));
            if ($prenom === '' || $nom === '') {
                $this->addFlash('error', 'Le prénom et le nom sont obligatoires.');
                return $this->redirectToRoute('app_account_settings');
            }
            $user->setPrenom($prenom);
            $user->setNom($nom);
            $user->setTelephone(trim((string) $request->request->get('telephone')) ?: null);
            $user->setFonction(trim((string) $request->request->get('fonction')) ?: null);
            $em->flush();
            $this->addFlash('success', 'Vos informations de profil ont été mises à jour.');
            return $this->redirectToRoute('app_account_settings');
        }

        if ($request->isMethod('POST') && $request->request->get('action') === 'password') {
            if (!$this->isCsrfTokenValid('account-password', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
            }
            $current = (string) $request->request->get('current_password');
            $new = (string) $request->request->get('new_password');
            $confirmation = (string) $request->request->get('confirm_password');

            if (!$hasher->isPasswordValid($user, $current)) {
                $this->addFlash('error', 'Le mot de passe actuel est incorrect.');
            } elseif (strlen($new) < 12 || !preg_match('/[A-Z]/', $new) || !preg_match('/[a-z]/', $new) || !preg_match('/\d/', $new)) {
                $this->addFlash('error', 'Le nouveau mot de passe doit contenir 12 caractères, une majuscule, une minuscule et un chiffre.');
            } elseif (strtolower($new) === strtolower($user->getUserIdentifier())) {
                $this->addFlash('error', 'Le mot de passe ne peut pas être identique à votre adresse email.');
            } elseif (strtolower($new) === strtolower($user->getNomComplet())) {
                $this->addFlash('error', 'Le mot de passe ne peut pas être identique à votre nom.');
            } elseif ($new !== $confirmation) {
                $this->addFlash('error', 'La confirmation du nouveau mot de passe ne correspond pas.');
            } else {
                $user->setPassword($hasher->hashPassword($user, $new));
                $em->flush();

                // Invalider toutes les sessions actives après changement de mot de passe
                // (sécurité : empêche la réutilisation d'une session volée)
                $session = $request->getSession();
                if ($session !== null) {
                    $session->invalidate();
                }

                // Après invalidation de session, l'utilisateur DOIT se reconnecter.
                // On ne peut plus ajouter de flash (session détruite) ni rediriger vers une page protégée.
                return $this->redirectToRoute('app_login');
            }
            return $this->redirectToRoute('app_account_settings');
        }

        return $this->render('account/settings.html.twig', ['user' => $user]);
    }

    // ── 2FA TOTP : page de configuration ─────────────────────────────────────

    #[Route('/securite/2fa', name: 'app_2fa_settings', methods: ['GET'])]
    public function twoFactorSettings(EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        // Générer un secret temporaire si l'utilisateur n'en a pas encore
        // Ou si le secret stocké n'est pas un secret TOTP valide (base32, 16-32 chars)
        $existingSecret = $user->getTotpSecret();
        $needNewSecret  = !$existingSecret
            || strlen($existingSecret) > 64
            || !preg_match('/^[A-Z2-7]+=*$/i', $existingSecret);

        if ($needNewSecret) {
            $secret = $this->totpAuthenticator->generateSecret();
            $user->setTotpSecret($secret);
            $em->flush();
        }

        $qrCodeUrl = $this->totpAuthenticator->getQRContent($user);

        // Générer le QR code en base64 localement (endroid/qr-code v6)
        $qrCodeBase64 = null;
        $qrError = null;
        try {
            $qr = new QrCode(
                data: $qrCodeUrl,
                encoding: new Encoding('UTF-8'),
                errorCorrectionLevel: ErrorCorrectionLevel::Low,
                size: 220,
                margin: 10,
                roundBlockSizeMode: RoundBlockSizeMode::Margin,
                foregroundColor: new Color(0, 0, 0),
                backgroundColor: new Color(255, 255, 255),
            );
            $result = (new PngWriter())->write($qr);
            $qrCodeBase64 = 'data:image/png;base64,' . base64_encode($result->getString());
        } catch (\Throwable $e) {
            // Fallback silencieux — le template utilisera l'API externe
            $qrError = $e->getMessage();
        }

        return $this->render('account/2fa.html.twig', [
            'user'           => $user,
            'totp_enabled'   => $user->isTotpEnabled(),
            'secret'         => $user->getTotpSecret(),
            'qr_code_url'    => $qrCodeUrl,
            'qr_code_base64' => $qrCodeBase64,
        ]);
    }

    // ── 2FA TOTP : activation ─────────────────────────────────────────────────

    #[Route('/securite/2fa/activer', name: 'app_2fa_enable', methods: ['POST'])]
    public function twoFactorEnable(Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('enable_2fa', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('app_2fa_settings');
        }

        $code = (string) $request->request->get('code');

        if ($this->totpAuthenticator->checkCode($user, $code)) {
            $user->setTotpEnabled(true);
            $em->flush();
            $this->addFlash('success', '✅ Authentification à deux facteurs activée avec succès. Votre compte est maintenant protégé.');
        } else {
            $this->addFlash('error', '❌ Code invalide. Vérifiez l\'heure de votre appareil et réessayez.');
        }

        return $this->redirectToRoute('app_2fa_settings');
    }

    // ── 2FA TOTP : désactivation ──────────────────────────────────────────────

    #[Route('/securite/2fa/desactiver', name: 'app_2fa_disable', methods: ['POST'])]
    public function twoFactorDisable(Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('disable_2fa', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('app_2fa_settings');
        }

        $user->setTotpEnabled(false);
        $user->setTotpSecret(null);
        $em->flush();

        $this->addFlash('success', 'L\'authentification à deux facteurs a été désactivée.');
        return $this->redirectToRoute('app_2fa_settings');
    }
}
