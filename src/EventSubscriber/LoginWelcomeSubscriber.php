<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

final class LoginWelcomeSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [LoginSuccessEvent::class => 'onLoginSuccess'];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $event->getRequest()->getSession()->getFlashBag()->add(
            'login_welcome',
            'Bienvenue sur GEI System',
        );
    }
}
