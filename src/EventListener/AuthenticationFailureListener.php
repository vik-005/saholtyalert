<?php

namespace App\EventListener;

use App\Entity\SecurityAuditLog;
use App\Service\RiskScoringService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Psr\Log\LoggerInterface;

/**
 * Listener pour les tentatives d'authentification échouées.
 * Enregistre les tentatives de connexion avec identifiants incorrects.
 */
class AuthenticationFailureListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RiskScoringService $riskScoringService,
        private readonly RequestStack $requestStack,
        private readonly LoggerInterface $logger,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            LoginFailureEvent::class => ['onAuthenticationFailure', 0],
        ];
    }

    public function onAuthenticationFailure(LoginFailureEvent $event): void
    {
        try {
            $request = $this->requestStack->getCurrentRequest();
            if ($request === null) {
                return;
            }

            $exception = $event->getException();
            $message = $exception->getMessage();

            // Déterminer le type d'erreur
            $eventType = match (true) {
                str_contains($message, 'not found') => SecurityAuditLog::EVENT_UNKNOWN_ACCOUNT,
                str_contains($message, 'Invalid credentials') => SecurityAuditLog::EVENT_LOGIN_FAILED,
                str_contains($message, 'disabled') => SecurityAuditLog::EVENT_LOGIN_FAILED,
                default => SecurityAuditLog::EVENT_LOGIN_FAILED,
            };

            $auditLog = new SecurityAuditLog();
            $auditLog->setEventType($eventType);
            $auditLog->setIpAddress($request->getClientIp() ?? 'unknown');
            $auditLog->setUserAgent($request->headers->get('User-Agent'));
            $auditLog->setHttpMethod($request->getMethod());
            $auditLog->setUrlPath($request->getPathInfo());
            $auditLog->setHttpStatusCode(401);
            $auditLog->setRejectionReason($message);

            // Calcul du score de risque
            $riskResult = $this->riskScoringService->calculateRiskScore(
                $eventType,
                $auditLog->getIpAddress(),
                $auditLog->getUserAgent(),
                null,
                false,
                false,
                false,
                1, // Une tentative échouée
            );

            $auditLog->setRiskScore($riskResult['score']);
            $auditLog->setRiskLevel($riskResult['level']);
            $auditLog->setRiskFactors($riskResult['factors']);

            $this->entityManager->persist($auditLog);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Authentication failure audit logging failed', ['exception' => $e]);
        }
    }
}
