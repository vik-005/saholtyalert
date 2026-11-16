<?php

namespace App\EventSubscriber;

use App\Entity\SecurityAuditLog;
use App\Service\RiskScoringService;
use App\Service\IntrusionDetectionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use Symfony\Component\Security\Http\SecurityEvents;
use Symfony\Bundle\SecurityBundle\Security;
use Psr\Log\LoggerInterface;

/**
 * Subscriber principal d'audit de sécurité.
 * Capture tous les événements HTTP et d'authentification.
 * Ne jamais bloquer avec des flushes dans les hooks critiques.
 */
class SecurityAuditSubscriber implements EventSubscriberInterface
{
    private ?RequestEvent $currentRequest = null;
    private array $requestStartTime = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RiskScoringService $riskScoringService,
        private readonly IntrusionDetectionService $intrusionDetectionService,
        private readonly Security $security,
        private readonly LoggerInterface $logger,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 1024],
            KernelEvents::RESPONSE => ['onKernelResponse', -1024],
            KernelEvents::EXCEPTION => ['onKernelException', 0],
            SecurityEvents::INTERACTIVE_LOGIN => ['onInteractiveLogin', 0],
            LogoutEvent::class => ['onLogout', 0],
        ];
    }

    /**
     * Capture les informations de la requête au démarrage
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $requestId = uniqid('req_', true);
        
        $this->currentRequest = $event;
        $this->requestStartTime[$requestId] = microtime(true);

        // Stocker l'ID de requête dans les attributs pour la corrélation
        $request->attributes->set('_audit_correlation_id', $requestId);
    }

    /**
     * Capture les informations de réponse HTTP
     */
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        try {
            $request = $event->getRequest();
            $response = $event->getResponse();

            // Filtrer les ressources statiques
            if ($this->isStaticResource($request)) {
                return;
            }

            $auditLog = $this->createAuditLog(
                $request,
                (int) $response->getStatusCode(),
                microtime(true)
            );

            if ($auditLog !== null) {
                $this->entityManager->persist($auditLog);
                
                // Analyser pour détection d'intrusions
                $this->intrusionDetectionService->analyzeEvent($auditLog);

                // Flush immédiat pour garantir la persistance
                $this->entityManager->flush();
            }
        } catch (\Throwable $e) {
            $this->logger->error('Security audit logging failed', ['exception' => $e]);
        }
    }

    /**
     * Capture les exceptions HTTP
     */
    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        try {
            $request = $event->getRequest();
            $exception = $event->getThrowable();

            $auditLog = new SecurityAuditLog();
            $auditLog->setIpAddress($request->getClientIp() ?? 'unknown');
            $auditLog->setUserAgent($request->headers->get('User-Agent'));
            $auditLog->setHttpMethod($request->getMethod());
            $auditLog->setUrlPath($request->getPathInfo());
            $auditLog->setRouteName($request->attributes->get('_route'));
            
            // Déterminer le type d'événement basé sur l'exception
            if (str_contains($exception->getMessage(), 'Access Denied')) {
                $auditLog->setEventType(SecurityAuditLog::EVENT_FORBIDDEN_ACCESS);
                $auditLog->setHttpStatusCode(403);
            } else {
                $auditLog->setEventType(SecurityAuditLog::EVENT_HTTP_ERROR);
                $auditLog->setHttpStatusCode(500);
            }

            $auditLog->setRejectionReason($exception->getMessage());

            $riskResult = $this->riskScoringService->calculateRiskScore(
                $auditLog->getEventType(),
                $auditLog->getIpAddress(),
                $auditLog->getUserAgent(),
                null,
                false,
                false,
                false
            );

            $auditLog->setRiskScore($riskResult['score']);
            $auditLog->setRiskLevel($riskResult['level']);
            $auditLog->setRiskFactors($riskResult['factors']);

            $this->entityManager->persist($auditLog);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Exception audit logging failed', ['exception' => $e]);
        }
    }

    /**
     * Capture les connexions réussies
     */
    public function onInteractiveLogin(InteractiveLoginEvent $event): void
    {
        try {
            $user = $event->getAuthenticationToken()->getUser();
            $request = $event->getRequest();

            $auditLog = new SecurityAuditLog();
            $auditLog->setEventType(SecurityAuditLog::EVENT_LOGIN_SUCCESS);
            $auditLog->setUser($user);
            $auditLog->setIpAddress($request->getClientIp() ?? 'unknown');
            $auditLog->setUserAgent($request->headers->get('User-Agent'));
            $auditLog->setHttpMethod($request->getMethod());
            $auditLog->setUrlPath($request->getPathInfo());
            $auditLog->setHttpStatusCode(200);

            $riskResult = $this->riskScoringService->calculateRiskScore(
                SecurityAuditLog::EVENT_LOGIN_SUCCESS,
                $auditLog->getIpAddress(),
                $auditLog->getUserAgent(),
                null,
                false,
                false,
                false
            );

            $auditLog->setRiskScore($riskResult['score']);
            $auditLog->setRiskLevel($riskResult['level']);
            $auditLog->setRiskFactors($riskResult['factors']);

            $this->entityManager->persist($auditLog);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Login audit logging failed', ['exception' => $e]);
        }
    }

    /**
     * Capture les déconnexions
     */
    public function onLogout(LogoutEvent $event): void
    {
        try {
            $token = $event->getToken();
            if ($token === null) {
                return;
            }

            $user = $token->getUser();
            if (!is_object($user)) {
                return;
            }

            $request = $event->getRequest();

            $auditLog = new SecurityAuditLog();
            $auditLog->setEventType(SecurityAuditLog::EVENT_LOGOUT);
            $auditLog->setUser($user);
            $auditLog->setIpAddress($request->getClientIp() ?? 'unknown');
            $auditLog->setUserAgent($request->headers->get('User-Agent'));
            $auditLog->setHttpMethod($request->getMethod());
            $auditLog->setUrlPath($request->getPathInfo());
            $auditLog->setHttpStatusCode(200);

            $this->entityManager->persist($auditLog);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Logout audit logging failed', ['exception' => $e]);
        }
    }

    /**
     * Crée un log d'audit pour une requête HTTP
     */
    private function createAuditLog(
        \Symfony\Component\HttpFoundation\Request $request,
        int $statusCode,
        float $endTime
    ): ?SecurityAuditLog {
        // Déterminer le type d'événement
        $eventType = match (true) {
            $statusCode === 404 => SecurityAuditLog::EVENT_ROUTE_NOT_FOUND,
            $statusCode === 403 => SecurityAuditLog::EVENT_FORBIDDEN_ACCESS,
            $statusCode >= 400 && $statusCode < 500 => SecurityAuditLog::EVENT_HTTP_ERROR,
            $statusCode >= 500 => SecurityAuditLog::EVENT_HTTP_ERROR,
            default => SecurityAuditLog::EVENT_ROUTE_ACCESSED,
        };

        $auditLog = new SecurityAuditLog();
        $auditLog->setEventType($eventType);
        $auditLog->setHttpStatusCode($statusCode);
        $auditLog->setHttpMethod($request->getMethod());
        $auditLog->setUrlPath($request->getPathInfo());
        $auditLog->setRouteName($request->attributes->get('_route'));
        $auditLog->setIpAddress($request->getClientIp() ?? 'unknown');
        $auditLog->setUserAgent($request->headers->get('User-Agent'));

        $user = $this->security->getUser();
        if ($user !== null && method_exists($user, 'getId')) {
            $auditLog->setUser($user);
        }

        // Calcul du score de risque
        $riskResult = $this->riskScoringService->calculateRiskScore(
            $eventType,
            $auditLog->getIpAddress(),
            $auditLog->getUserAgent(),
            null, // Géolocalisation sera ajoutée par un autre subscriber
            false,
            false,
            false
        );

        $auditLog->setRiskScore($riskResult['score']);
        $auditLog->setRiskLevel($riskResult['level']);
        $auditLog->setRiskFactors($riskResult['factors']);

        return $auditLog;
    }

    /**
     * Vérifie si la ressource est statique (CSS, JS, images)
     */
    private function isStaticResource(\Symfony\Component\HttpFoundation\Request $request): bool
    {
        $path = $request->getPathInfo();
        $staticExtensions = ['.css', '.js', '.png', '.jpg', '.jpeg', '.gif', '.svg', '.woff', '.woff2', '.ttf'];

        foreach ($staticExtensions as $ext) {
            if (str_ends_with($path, $ext)) {
                return true;
            }
        }

        return false;
    }
}
