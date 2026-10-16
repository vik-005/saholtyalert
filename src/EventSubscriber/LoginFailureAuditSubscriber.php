<?php

namespace App\EventSubscriber;

use App\Entity\AccessLog;
use App\Repository\AccessLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

final class LoginFailureAuditSubscriber implements EventSubscriberInterface
{
    private const FAILURE_WINDOW_MINUTES = 15;
    private const SUSPICIOUS_ATTEMPT_THRESHOLD = 5;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccessLogRepository $logRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [LoginFailureEvent::class => 'onLoginFailure'];
    }

    public function onLoginFailure(LoginFailureEvent $event): void
    {
        $request = $event->getRequest();
        $ip = $request->getClientIp();
        $log = new AccessLog();
        $log->setAction(AccessLog::ACTION_LOGIN_FAILED);
        $log->setResultat('denied');
        $log->setIpAdresse($ip);
        $log->setUserAgent($request->headers->get('User-Agent'));

        try {
            $windowStart = (new \DateTimeImmutable())
                ->modify(sprintf('-%d minutes', self::FAILURE_WINDOW_MINUTES));
            $crossedThreshold = $ip !== null
                && $this->logRepository->countLoginFailuresFromIpSince($ip, $windowStart) >= self::SUSPICIOUS_ATTEMPT_THRESHOLD - 1
                && !$this->logRepository->hasRecentSuspiciousLoginFromIp($ip, $windowStart);

            if ($crossedThreshold) {
                $log->setAction(AccessLog::ACTION_LOGIN_SUSPICIOUS);
                $log->setDetails('Seuil de 5 échecs d’authentification depuis cette adresse IP en 15 minutes atteint.');
            } else {
                $log->setDetails('Échec d’authentification.');
            }

            $this->em->persist($log);
            $this->em->flush();
        } catch (\Throwable $exception) {
            $this->logger->error('Échec de persistance de l’audit d’authentification.', [
                'ip' => $ip,
                'exception' => $exception,
            ]);
        }
    }
}
