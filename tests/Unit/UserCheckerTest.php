<?php

namespace App\Tests\Unit;

use App\Entity\User;
use App\Security\UserChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\DisabledException;

class UserCheckerTest extends TestCase
{
    public function testDisabledUserCannotAuthenticate(): void
    {
        $user = new User();
        $user->setActif(false);

        $this->expectException(DisabledException::class);

        (new UserChecker())->checkPreAuth($user);
    }

    public function testActiveUserPassesPreAuthenticationCheck(): void
    {
        $user = new User();

        (new UserChecker())->checkPreAuth($user);

        self::assertTrue($user->isActif());
    }
}
