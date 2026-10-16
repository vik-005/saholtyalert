<?php

namespace App\Tests\Unit;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

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
}
