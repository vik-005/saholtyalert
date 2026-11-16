<?php

namespace App\Controller;

use App\Service\WebauthnService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/login/webauthn')]
final class WebauthnAuthController extends AbstractController
{
    public function __construct(
        private readonly WebauthnService $webauthnService,
    ) {
    }

    #[Route('/options', name: 'app_login_webauthn_options', methods: ['GET'])]
    public function options(Request $request): JsonResponse
    {
        $result = $this->webauthnService->createAuthenticationOptions($request);

        return new JsonResponse($result['options']);
    }

    #[Route('/check', name: 'app_login_webauthn_check', methods: ['POST'])]
    public function check(Request $request): JsonResponse
    {
        // L'authentification est déjà effectuée par WebauthnAuthenticator (onAuthenticationSuccess
        // redirige déjà). Ce point d'entrée existe pour que le firewall reconnaisse la route.
        return new JsonResponse(['success' => true]);
    }
}
