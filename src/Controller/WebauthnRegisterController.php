<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\WebauthnCredential;
use App\Repository\WebauthnCredentialRepository;
use App\Service\AdminAuditLogger;
use App\Service\WebauthnService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/compte/securite/webauthn')]
#[IsGranted('ROLE_USER')]
final class WebauthnRegisterController extends AbstractController
{
    public function __construct(
        private readonly WebauthnService $webauthnService,
        private readonly EntityManagerInterface $entityManager,
        private readonly AdminAuditLogger $auditLogger,
    ) {
    }

    #[Route('', name: 'app_webauthn_settings', methods: ['GET'])]
    public function settings(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('account/webauthn.html.twig', [
            'credentials' => $user->getWebauthnCredentials(),
            'hasCredential' => $user->hasWebauthnCredential(),
        ]);
    }

    #[Route('/register/options', name: 'app_webauthn_register_options', methods: ['POST'])]
    public function registerOptions(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('webauthn_register', (string) $request->request->get('_token'))) {
            return new JsonResponse(['error' => 'Jeton de sécurité invalide.'], 403);
        }

        $result = $this->webauthnService->createRegistrationOptions($user, $request);

        return new JsonResponse($result['options']);
    }

    #[Route('/register', name: 'app_webauthn_register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('webauthn_register', (string) $request->request->get('_token'))) {
            return new JsonResponse(['error' => 'Jeton de sécurité invalide.'], 403);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !isset($data['response'], $data['name'])) {
            return new JsonResponse(['error' => 'Données d’enregistrement WebAuthn incomplètes.'], 400);
        }

        try {
            $record = $this->webauthnService->completeRegistration($data['response'], $user, $request);
            $this->webauthnService->persistCredentialRecord(
                $user,
                $record,
                (string) $data['name']
            );
        } catch (\Throwable $throwable) {
            return new JsonResponse(['error' => $throwable->getMessage() ?: 'Échec de l’enregistrement WebAuthn.'], 400);
        }

        $this->auditLogger->logAndFlush('webauthn_registered', 'webauthn_credential', $user->getId());

        return new JsonResponse(['success' => true]);
    }

    #[Route('/remove', name: 'app_webauthn_remove', methods: ['POST'])]
    public function remove(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('webauthn_remove', (string) $request->request->get('_token'))) {
            return new JsonResponse(['error' => 'Jeton de sécurité invalide.'], 403);
        }

        $credentialId = (string) $request->request->get('credential_id');
        /** @var WebauthnCredentialRepository $repo */
        $repo = $this->entityManager->getRepository(WebauthnCredential::class);
        $credential = $repo->findOneByCredentialId($credentialId);
        if (null === $credential || $credential->getUser() !== $user) {
            return new JsonResponse(['error' => 'Credential introuvable.'], 404);
        }

        if ($user->getWebauthnCredentials()->count() <= 1) {
            return new JsonResponse(['error' => 'Impossible de supprimer la dernière clé WebAuthn.'], 400);
        }

        $this->webauthnService->removeCredential($credential);
        $this->auditLogger->logAndFlush('webauthn_removed', 'webauthn_credential', $user->getId());

        return new JsonResponse(['success' => true]);
    }
}
