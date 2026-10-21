<?php

namespace App\Service;

use App\Entity\Notification;
use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class AblyRealtimeService
{
    private const TOKEN_TTL = 3_600_000;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $apiKey,
    ) {}

    public function isConfigured(): bool
    {
        return $this->getCredentials() !== null;
    }

    /**
     * @return array{keyName: string, ttl: int, capability: string, clientId: string, timestamp: int, nonce: string, mac: string}
     */
    public function createTokenRequest(User $user): array
    {
        $credentials = $this->getCredentials();
        $userId = $user->getId();
        if ($credentials === null || $userId === null) {
            throw new \LogicException('Ably doit être configuré et l’utilisateur doit être persisté.');
        }

        [$keyName, $secret] = $credentials;
        $channel = $this->channelName($user);
        $ttl = self::TOKEN_TTL;
        $capability = json_encode([$channel => ['subscribe']], JSON_THROW_ON_ERROR);
        $clientId = (string) $userId;
        $timestamp = (int) floor(microtime(true) * 1000);
        $nonce = bin2hex(random_bytes(16));
        $signingKey = implode("\n", [$keyName, $ttl, $capability, $clientId, $timestamp, $nonce]);

        return [
            'keyName' => $keyName,
            'ttl' => $ttl,
            'capability' => $capability,
            'clientId' => $clientId,
            'timestamp' => $timestamp,
            'nonce' => $nonce,
            'mac' => base64_encode(hash_hmac('sha256', $signingKey, $secret, true)),
        ];
    }

    public function publish(Notification $notification): void
    {
        $credentials = $this->getCredentials();
        $user = $notification->getDestinataire();
        if ($credentials === null || !$user instanceof User) {
            return;
        }

        if ($notification->getId() === null) {
            $this->logger->error('Impossible de publier une notification Ably non persistée.');
            return;
        }

        [$keyName, $secret] = $credentials;
        try {
            $response = $this->httpClient->request(
                'POST',
                'https://rest.ably.io/channels/' . rawurlencode($this->channelName($user)) . '/messages',
                [
                    'auth_basic' => [$keyName, $secret],
                    'json' => [[
                        'name' => 'notification.created',
                        'data' => ['id' => $notification->getId()],
                    ]],
                    'timeout' => 3.0,
                ],
            );

            $statusCode = $response->getStatusCode();
            if ($statusCode < 200 || $statusCode >= 300) {
                $this->logger->error('La notification GEI a été conservée, mais sa publication Ably a échoué.', [
                    'notification_id' => $notification->getId(),
                    'http_status' => $statusCode,
                ]);
            }
        } catch (TransportExceptionInterface $exception) {
            $this->logger->error('La notification GEI a été conservée, mais Ably est injoignable.', [
                'notification_id' => $notification->getId(),
                'exception' => $exception,
            ]);
        }
    }

    public function channelName(User $user): string
    {
        $userId = $user->getId();
        if ($userId === null) {
            throw new \LogicException('Un canal Ably ne peut être créé que pour un utilisateur persisté.');
        }

        return 'gei-user-' . $userId;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function getCredentials(): ?array
    {
        $separator = strpos($this->apiKey, ':');
        if ($separator === false || $separator === 0 || $separator === strlen($this->apiKey) - 1) {
            return null;
        }

        $keyName = substr($this->apiKey, 0, $separator);
        $secret = substr($this->apiKey, $separator + 1);
        if (str_contains($keyName, "\n") || str_contains($secret, "\n")) {
            return null;
        }

        return [$keyName, $secret];
    }
}
