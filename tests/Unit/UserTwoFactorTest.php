<?php

namespace App\Tests\Unit;

use App\Entity\User;
use App\Enum\UserRoleEnum;
use App\EventSubscriber\TwoFactorEnforcementSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\RouterInterface;

class UserTwoFactorTest extends TestCase
{
    public function testTotpConfigurationIsAvailableWhileSettingUpTwoFactor(): void
    {
        $user = new User();
        $user->setTotpSecret('JBSWY3DPEHPK3PXP');

        self::assertFalse($user->isTotpAuthenticationEnabled());
        self::assertNotNull($user->getTotpAuthenticationConfiguration());
    }

    public function testTotpConfigurationIsUnavailableWithoutASecret(): void
    {
        $user = new User();

        self::assertNull($user->getTotpAuthenticationConfiguration());
    }

    public function testTwoFactorIsRequiredOnlyForPrivilegedRoles(): void
    {
        self::assertTrue(UserRoleEnum::SUPERADMIN->requiresTwoFactor());
        self::assertTrue(UserRoleEnum::SAHOLTY->requiresTwoFactor());
        self::assertTrue(UserRoleEnum::PFT->requiresTwoFactor());
        self::assertFalse(UserRoleEnum::EMETTEUR_TERRAIN->requiresTwoFactor());
        self::assertFalse(UserRoleEnum::SECRETARIAT_GEI->requiresTwoFactor());
        self::assertFalse(UserRoleEnum::COMITE_AIT->requiresTwoFactor());
    }

    public function testUnenrolledPrivilegedUserIsRedirectedToTwoFactorSetup(): void
    {
        $user = (new User())->setRole(UserRoleEnum::PFT);
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);
        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')->with('app_2fa_settings')->willReturn('/compte/securite/2fa');
        $request = Request::create('/dashboard');
        $request->attributes->set('_route', 'app_dashboard_operationnel');
        $event = new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );

        (new TwoFactorEnforcementSubscriber($security, $router))->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        if ($response instanceof RedirectResponse) {
            self::assertSame('/compte/securite/2fa', $response->getTargetUrl());
        }
    }

    public function testTwoFactorSetupRemainsAccessibleBeforeEnrollment(): void
    {
        $user = (new User())->setRole(UserRoleEnum::PFT);
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);
        $router = $this->createMock(RouterInterface::class);
        $request = Request::create('/compte/securite/2fa');
        $request->attributes->set('_route', 'app_2fa_settings');
        $event = new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );

        (new TwoFactorEnforcementSubscriber($security, $router))->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testEnabledFlagWithoutUsableSecretDoesNotBypassSetup(): void
    {
        $user = (new User())
            ->setRole(UserRoleEnum::SUPERADMIN)
            ->setTotpEnabled(true);
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);
        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')->with('app_2fa_settings')->willReturn('/compte/securite/2fa');
        $request = Request::create('/dashboard');
        $request->attributes->set('_route', 'app_dashboard_operationnel');
        $event = new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );

        (new TwoFactorEnforcementSubscriber($security, $router))->onKernelRequest($event);

        self::assertInstanceOf(RedirectResponse::class, $event->getResponse());
    }
}
