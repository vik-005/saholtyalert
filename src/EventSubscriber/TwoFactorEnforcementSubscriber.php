<?php

namespace App\EventSubscriber;

use App\Entity\User;
use App\Enum\UserRoleEnum;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

class TwoFactorEnforcementSubscriber implements EventSubscriberInterface
{
    private Security $security;
    private RouterInterface $router;

    public function __construct(Security $security, RouterInterface $router)
    {
        $this->security = $security;
        $this->router = $router;
    }

    public static function getSubscribedEvents(): array
    {
        // L'activation du 2FA est volontaire : aucune contrainte/redirection automatique forcée
        return [];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        // Pas de contrainte forcée
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        // Pas de redirection forcée
    }
}