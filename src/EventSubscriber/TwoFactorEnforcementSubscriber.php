<?php

namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Bundle\SecurityBundle\Security;

class TwoFactorEnforcementSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly RouterInterface $router,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 0]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User || !$user->getRole()->requiresTwoFactor()) {
            return;
        }

        if ($user->isTotpEnabled() && $user->getTotpAuthenticationConfiguration() !== null) {
            return;
        }

        $route = $event->getRequest()->attributes->get('_route');
        if (in_array($route, ['app_2fa_settings', 'app_2fa_enable', 'app_logout'], true)) {
            return;
        }

        $event->setResponse(new RedirectResponse($this->router->generate('app_2fa_settings')));
    }
}