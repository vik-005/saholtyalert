<?php

namespace App\EventSubscriber;

use App\Entity\User;
use App\Enum\UserRoleEnum;
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
        $flashBag = $event->getRequest()->getSession()->getFlashBag();
        $flashBag->add('login_welcome', 'Bienvenue sur GEI System');

        $user = $event->getUser();
        if ($user instanceof User && in_array($user->getRole(), [
            UserRoleEnum::PFT,
            UserRoleEnum::SAHOLTY,
            UserRoleEnum::SUPERADMIN,
        ], true)) {
            $flashBag->add('login_alert_summary', '1');
        }
    }
}
