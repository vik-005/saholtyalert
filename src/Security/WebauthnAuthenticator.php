<?php

namespace App\Security;

use App\Entity\AccessLog;
use App\Service\AdminAuditLogger;
use App\Service\WebauthnService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Authentifie un utilisateur par empreinte digitale (WebAuthn / passkey) sans mot de passe.
 *
 * Route ciblée : app_login_webauthn_check (POST, JSON).
 * Le navigateur transmet l'assertion WebAuthn ; le service valide la signature et
 * retourne l'utilisateur associé à la clef résidente.
 */
final class WebauthnAuthenticator extends AbstractAuthenticator
{
    use TargetPathTrait;

    public const ROUTE_LOGIN_CHECK = 'app_login_webauthn_check';

    public function __construct(
        private readonly WebauthnService $webauthnService,
        private readonly EntityManagerInterface $entityManager,
        private readonly AdminAuditLogger $auditLogger,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function supports(Request $request): bool
    {
        return self::ROUTE_LOGIN_CHECK === $request->attributes->get('_route')
            && $request->isMethod('POST');
    }

    public function authenticate(Request $request): SelfValidatingPassport
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !isset($data['response'])) {
            throw new CustomUserMessageAuthenticationException('Requête d’authentification WebAuthn invalide.');
        }

        try {
            $user = $this->webauthnService->completeAuthentication($data, $request);
        } catch (\Throwable $throwable) {
            $this->auditLogger->log(AccessLog::ACTION_LOGIN_FAILED, 'webauthn', '?');
            $this->entityManager->flush();
            $this->logger->warning('WebAuthn authentication failed', [
                'exception' => $throwable->getMessage(),
            ]);
            throw new CustomUserMessageAuthenticationException('L’authentification par empreinte a échoué.');
        }

        $this->auditLogger->log(AccessLog::ACTION_LOGIN, 'webauthn', $user->getId());
        $this->entityManager->flush();

        return new SelfValidatingPassport(new UserBadge($user->getUserIdentifier()), []);
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        $target = $this->getTargetPath($request->getSession(), $firewallName);
        if ('' === $target) {
            $target = '/operationnel';
        }

        return new JsonResponse([
            'success' => true,
            'redirect' => $target,
        ]);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return new JsonResponse([
            'success' => false,
            'message' => $exception->getMessageKey() ?: $exception->getMessage(),
        ], 401);
    }

    public function supportsRememberMe(): bool
    {
        return false;
    }
}
