<?php

namespace App\Service;

use App\Entity\Notification;
use App\Entity\PushSubscription;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Log\LoggerInterface;

class WebPushService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly string $publicKey,
        private readonly string $privateKey,
        private readonly string $subject,
    ) {}

    public function isConfigured(): bool
    {
        return $this->publicKey !== '' && $this->privateKey !== '' && $this->subject !== '';
    }

    public function getPublicKey(): string
    {
        return $this->publicKey;
    }

    public function send(Notification $notification): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        $user = $notification->getDestinataire();
        if (!$user instanceof User) {
            return;
        }

        $subscriptions = $this->em->getRepository(PushSubscription::class)->findBy(['user' => $user]);
        if ($subscriptions === []) {
            return;
        }

        try {
            $payload = json_encode([
                'title' => $notification->isUrgente() ? 'Alerte GEI prioritaire' : 'Nouvelle notification GEI',
                'body' => mb_substr(strip_tags($notification->getContenu()), 0, 180),
                'url' => '/notification/' . $notification->getId() . '/read',
                'tag' => 'gei-notification-' . $notification->getId(),
                'urgent' => $notification->isUrgente(),
            ], JSON_THROW_ON_ERROR);

            $webPush = new WebPush(
                ['VAPID' => [
                    'subject' => $this->subject,
                    'publicKey' => $this->publicKey,
                    'privateKey' => $this->privateKey,
                ]],
                ['TTL' => 300, 'urgency' => $notification->isUrgente() ? 'high' : 'normal'],
                5,
            );

            foreach ($subscriptions as $savedSubscription) {
                $webPush->queueNotification(
                    new Subscription(
                        $savedSubscription->getEndpoint(),
                        $savedSubscription->getPublicKey(),
                        $savedSubscription->getAuthToken(),
                        'aes128gcm',
                    ),
                    $payload,
                );
            }

            foreach ($webPush->flush() as $report) {
                if ($report->isSuccess()) {
                    continue;
                }

                $this->logger->warning('Échec de livraison Web Push.', [
                    'endpoint_host' => parse_url($report->getEndpoint(), PHP_URL_HOST),
                    'reason' => $report->getReason(),
                ]);

                if ($report->isSubscriptionExpired()) {
                    $expired = $this->em->getRepository(PushSubscription::class)
                        ->findOneBy(['endpointHash' => hash('sha256', $report->getEndpoint())]);
                    if ($expired instanceof PushSubscription) {
                        $this->em->remove($expired);
                    }
                }
            }

            $this->em->flush();
        } catch (\Throwable $exception) {
            $this->logger->error('La notification GEI a été conservée, mais l’envoi Web Push a échoué.', [
                'notification_id' => $notification->getId(),
                'exception' => $exception,
            ]);
        }
    }
}
