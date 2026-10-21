<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\AblyRealtimeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class AblyController extends AbstractController
{
    public function __construct(
        private readonly AblyRealtimeService $ablyRealtimeService,
    ) {}

    #[Route('/api/ably/config', name: 'app_ably_config', methods: ['GET'])]
    public function config(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $this->json([
            'enabled' => $this->ablyRealtimeService->isConfigured(),
            'channel' => $this->ablyRealtimeService->isConfigured()
                ? $this->ablyRealtimeService->channelName($user)
                : null,
        ], headers: ['Cache-Control' => 'no-store, private']);
    }

    #[Route('/api/ably/auth', name: 'app_ably_auth', methods: ['GET'])]
    public function auth(): JsonResponse
    {
        if (!$this->ablyRealtimeService->isConfigured()) {
            return $this->json(
                ['error' => 'Les notifications temps réel Ably ne sont pas configurées.'],
                Response::HTTP_SERVICE_UNAVAILABLE,
                ['Cache-Control' => 'no-store, private'],
            );
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $this->json(
            $this->ablyRealtimeService->createTokenRequest($user),
            headers: ['Cache-Control' => 'no-store, private'],
        );
    }
}
