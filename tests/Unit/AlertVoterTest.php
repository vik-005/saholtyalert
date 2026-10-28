<?php

namespace App\Tests\Unit;

use App\Entity\Alert;
use App\Entity\AlertTransmission;
use App\Entity\Market;
use App\Entity\User;
use App\Enum\AlertStatut;
use App\Enum\TransmissionStatut;
use App\Enum\UserRoleEnum;
use App\Voter\AlertVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

class AlertVoterTest extends TestCase
{
    public function testAgentCannotViewOtherAgentAlert(): void
    {
        $voter = new AlertVoter();

        $agent1 = new User();
        $agent1->setRole(UserRoleEnum::EMETTEUR_TERRAIN);
        // Simulate ID 1
        $reflector1 = new \ReflectionProperty(User::class, 'id');
        $reflector1->setValue($agent1, 1);

        $agent2 = new User();
        $agent2->setRole(UserRoleEnum::EMETTEUR_TERRAIN);
        $reflector2 = new \ReflectionProperty(User::class, 'id');
        $reflector2->setValue($agent2, 2);

        $alertOfAgent1 = new Alert();
        $alertOfAgent1->setEmetteur($agent1);

        $tokenAgent2 = $this->createMock(TokenInterface::class);
        $tokenAgent2->method('getUser')->willReturn($agent2);

        // Agent2 trying to view Agent1's alert => DENIED (403)
        $result = $voter->vote($tokenAgent2, $alertOfAgent1, [AlertVoter::VIEW]);
        $this->assertEquals(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testManagerCanViewAndQualifyAlertsOutsideManagedMarkets(): void
    {
        $voter = new AlertVoter();

        $manager = new User();
        $manager->setRole(UserRoleEnum::PFT);

        $alert = new Alert();
        $alert->setMarket(new Market());

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($manager);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $alert, [AlertVoter::VIEW]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $alert, [AlertVoter::QUALIFY]));
    }

    public function testAgentCannotEditQualificationFields(): void
    {
        $voter = new AlertVoter();

        $agent = new User();
        $agent->setRole(UserRoleEnum::EMETTEUR_TERRAIN);

        $alert = new Alert();
        $alert->setEmetteur($agent);
        $alert->setStatut(AlertStatut::NOUVEAU);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($agent);

        // Agent trying to qualify alert => DENIED
        $result = $voter->vote($token, $alert, [AlertVoter::QUALIFY]);
        $this->assertEquals(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testManagerCanQualifyAndTransmitAnAlertThatWasAlreadyTransmitted(): void
    {
        $voter = new AlertVoter();
        $manager = new User();
        $manager->setRole(UserRoleEnum::PFT);

        $alert = new Alert();
        $alert->setStatut(AlertStatut::VALIDEE);
        $alert->setTransmission(TransmissionStatut::OUI);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($manager);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $alert, [AlertVoter::QUALIFY]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $alert, [AlertVoter::TRANSMIT]));
    }

    public function testTransmissionRecordCountsAsAlreadyTransmitted(): void
    {
        $alert = new Alert();
        $alert->getTransmissions()->add(new AlertTransmission());

        self::assertTrue($alert->hasBeenTransmitted());
    }

    public function testManagerHasThreeRequalificationsAndSuperadminIsUnlimited(): void
    {
        $alert = new Alert();
        $manager = new User();
        $manager->setRole(UserRoleEnum::PFT);
        $superadmin = new User();
        $superadmin->setRole(UserRoleEnum::SUPERADMIN);

        $alert->setRequalificationCount(2);
        self::assertTrue($alert->canBeRequalifiedBy($manager));

        $alert->setRequalificationCount(3);
        self::assertFalse($alert->canBeRequalifiedBy($manager));
        self::assertTrue($alert->canBeRequalifiedBy($superadmin));
    }
}
