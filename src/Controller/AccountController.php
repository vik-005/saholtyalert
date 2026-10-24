<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\AccessLog;
use App\Service\AdminAuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Color\Color;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/compte')]
#[IsGranted('ROLE_USER')]
final class AccountController extends AbstractController
{
    public function __construct(
        private readonly TotpAuthenticatorInterface $totpAuthenticator,
        private readonly LoggerInterface $logger,
        private readonly \App\Service\ProfilePhotoStorageService $profilePhotoStorage,
    ) {}

    #[Route('', name: 'app_account_settings', methods: ['GET', 'POST'])]
    public function settings(Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $hasher): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if ($request->isMethod('POST') && in_array($request->request->get('action'), ['profile', 'upload_photo', 'remove_photo'], true)) {
            if (!$this->isCsrfTokenValid('account-profile', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
            }

            if ($request->request->get('action') === 'remove_photo') {
                $photoPath = $user->getPhotoProfil();
                $user->setPhotoProfil(null);
                $em->flush();
                $this->profilePhotoStorage->remove($photoPath, (int) $user->getId());
                $this->addFlash('success', 'Votre photo de profil a été supprimée.');
                return $this->redirectToRoute('app_account_settings');
            }

            if ($request->request->get('action') === 'upload_photo') {
                $photo = $request->files->get('photo_profil');
                if (!$photo instanceof UploadedFile) {
                    $this->addFlash('error', 'Choisissez une photo avant de l’enregistrer.');
                    return $this->redirectToRoute('app_account_settings');
                }

                $oldPhotoPath = $user->getPhotoProfil();
                try {
                    $newPhotoPath = $this->profilePhotoStorage->store($photo, (int) $user->getId());
                } catch (\InvalidArgumentException $exception) {
                    $this->addFlash('error', $exception->getMessage());
                    return $this->redirectToRoute('app_account_settings');
                }

                $user->setPhotoProfil($newPhotoPath);
                try {
                    $em->flush();
                } catch (\Throwable $exception) {
                    $this->profilePhotoStorage->remove($newPhotoPath, (int) $user->getId());
                    throw $exception;
                }

                if ($oldPhotoPath !== null) {
                    $this->profilePhotoStorage->remove($oldPhotoPath, (int) $user->getId());
                }
                $this->addFlash('success', 'Votre photo a été enregistrée et restera visible après rechargement.');
                return $this->redirectToRoute('app_account_settings');
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

        return $this->render('account/settings.html.twig', [
            'user' => $user,
            'totp_enabled' => $user->isTotpEnabled(),
            'photo_available' => $user->getPhotoProfil() !== null
                && $this->profilePhotoStorage->resolve($user->getPhotoProfil(), (int) $user->getId()) !== null,
        ]);
    }

    #[Route('/photo', name: 'app_account_photo', methods: ['GET'])]
    public function profilePhoto(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User || $user->getPhotoProfil() === null) {
            throw $this->createNotFoundException('Aucune photo de profil disponible.');
        }

        $storedPath = $user->getPhotoProfil();
        $path = $this->profilePhotoStorage->resolveOptimized($storedPath, (int) $user->getId());
        if ($path === null) {
            throw $this->createNotFoundException('La photo de profil est indisponible.');
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', $this->profilePhotoStorage->mimeType($storedPath));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->setPrivate();
        $response->setMaxAge(31_536_000);
        $response->setImmutable();
        $response->setEtag(hash('sha256', $storedPath));
        $response->setLastModified((new \DateTimeImmutable())->setTimestamp((int) filemtime($path)));
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, 'profile.webp');
        $response->isNotModified($request);

        return $response;
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
            $user->setTotpEnabled(false);
            $secret = $this->totpAuthenticator->generateSecret();
            $user->setTotpSecret($secret);
            $em->flush();
        }

        $qrCodeUrl = $this->totpAuthenticator->getQRContent($user);

        // Générer le QR code en base64 localement (endroid/qr-code v6)
        $qrCodeBase64 = null;
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
            $this->logger->error('Échec de génération locale du QR code 2FA.', [
                'user_id' => $user->getId(),
                'exception' => $e,
            ]);
        }

        return $this->render('account/2fa.html.twig', [
            'user'           => $user,
            'totp_enabled'   => $user->isTotpEnabled(),
            'secret'         => $user->getTotpSecret(),
            'qr_code_base64' => $qrCodeBase64,
        ]);
    }

    // ── 2FA TOTP : activation ─────────────────────────────────────────────────

    #[Route('/securite/2fa/activer', name: 'app_2fa_enable', methods: ['POST'])]
    public function twoFactorEnable(Request $request, EntityManagerInterface $em, AdminAuditLogger $auditLogger): Response
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
            $auditLogger->log(AccessLog::ACTION_MODIFICATION, 'user_2fa_enabled', $user->getId());
            $em->flush();
            $request->getSession()->invalidate();
            $this->addFlash('success', 'Authentification à deux facteurs activée. Reconnectez-vous pour la vérifier.');
            return $this->redirectToRoute('app_login');
        } else {
            $this->addFlash('error', 'Code invalide. Vérifiez l\'heure de votre appareil et réessayez.');
        }

        return $this->redirectToRoute('app_2fa_settings');
    }

    // ── 2FA TOTP : désactivation ──────────────────────────────────────────────

    #[Route('/securite/2fa/desactiver', name: 'app_2fa_disable', methods: ['POST'])]
    public function twoFactorDisable(Request $request, EntityManagerInterface $em, AdminAuditLogger $auditLogger): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if ($user->getRole()->requiresTwoFactor()) {
            throw $this->createAccessDeniedException('Le 2FA est obligatoire pour ce rôle.');
        }

        if (!$this->isCsrfTokenValid('disable_2fa', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('app_2fa_settings');
        }

        $user->setTotpEnabled(false);
        $user->setTotpSecret(null);
        $auditLogger->log(AccessLog::ACTION_MODIFICATION, 'user_2fa_disabled', $user->getId());
        $em->flush();

        $this->addFlash('success', 'L\'authentification à deux facteurs a été désactivée.');
        return $this->redirectToRoute('app_2fa_settings');
    }
}
