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
        return [
            LoginSuccessEvent::class => 'onLoginSuccess',
            RequestEvent::class => 'onKernelRequest',
        ];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        if ($event->getAuthenticatedToken() instanceof PostAuthenticationToken) {
            $event->getRequest()->getSession()->remove('2fa_required');
            $event->getRequest()->getSession()->remove('user_id_pending_2fa');
            return;
        }

        if (in_array($user->getRole(), [UserRoleEnum::SAHOLTY, UserRoleEnum::SUPERADMIN], true)
            && !$user->isTotpEnabled()) {
            $request = $event->getRequest();
            $request->getSession()->set('2fa_required', true);
            $request->getSession()->set('user_id_pending_2fa', $user->getId());
        }
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $session = $request->getSession();

        if (!$session->get('2fa_required')) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        if ($user->isTotpEnabled()) {
            $session->remove('2fa_required');
            $session->remove('user_id_pending_2fa');
            return;
        }

        if (!in_array($user->getRole(), [UserRoleEnum::SAHOLTY, UserRoleEnum::SUPERADMIN], true)) {
            $session->remove('2fa_required');
            $session->remove('user_id_pending_2fa');
            return;
        }

        if ($session->get('user_id_pending_2fa') !== $user->getId()) {
            $session->remove('2fa_required');
            $session->remove('user_id_pending_2fa');
            return;
        }

        $path = $request->getPathInfo();
        if ($path !== '/2fa' && $path !== '/2fa_check') {
            $response = new RedirectResponse($this->router->generate('2fa_login'));
            $event->setResponse($response);
        }
    }
}
