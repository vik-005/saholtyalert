<?php

namespace App\Tests\Unit;

use App\Entity\Market;
use App\Entity\User;
use App\Enum\UserRoleEnum;
use App\Voter\UserVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

class UserVoterTest extends TestCase
{
    private UserVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new UserVoter();
    }

    private function createToken(User $user): TokenInterface
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);
        return $token;
    }

    private function setUserWithId(User $user, int $id): User
    {
        $reflector = new \ReflectionProperty(User::class, 'id');
        $reflector->setValue($user, $id);
        return $user;
    }

    public function testSuperadminCanPerformAllUserActions(): void
    {
        $superadmin = new User();
        $superadmin->setRole(UserRoleEnum::SUPERADMIN);
        $this->setUserWithId($superadmin, 1);

        $otherUser = new User();
        $otherUser->setRole(UserRoleEnum::PFT);
        $this->setUserWithId($otherUser, 2);

        $token = $this->createToken($superadmin);

        $this->assertEquals(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $otherUser, [UserVoter::VIEW]));
        $this->assertEquals(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, null, [UserVoter::CREATE]));
        $this->assertEquals(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $otherUser, [UserVoter::EDIT]));
        $this->assertEquals(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $otherUser, [UserVoter::DELETE]));
    }

    public function testManagerCanManageAgentInHisMarket(): void
    {
        $marketBenin = new Market();
        $marketBenin->setNom('Bénin');
        $marketBenin->setCodeIso3('BEN');

        $manager = new User();
        $manager->setRole(UserRoleEnum::PFT);
        $manager->setMarket($marketBenin);
        $this->setUserWithId($manager, 10);

        $agentBenin = new User();
        $agentBenin->setRole(UserRoleEnum::EMETTEUR_TERRAIN);
        $agentBenin->setMarket($marketBenin);
        $this->setUserWithId($agentBenin, 20);

        $token = $this->createToken($manager);

        // Manager can view, edit and delete agent from his market
        $this->assertEquals(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $agentBenin, [UserVoter::VIEW]));
        $this->assertEquals(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $agentBenin, [UserVoter::EDIT]));
        $this->assertEquals(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, $agentBenin, [UserVoter::DELETE]));
        $this->assertEquals(VoterInterface::ACCESS_GRANTED, $this->voter->vote($token, null, [UserVoter::CREATE]));
    }

    public function testManagerCannotManageAgentInOtherMarket(): void
    {
        $marketBenin = new Market();
        $marketBenin->setNom('Bénin');
        $marketBenin->setCodeIso3('BEN');

        $marketTogo = new Market();
        $marketTogo->setNom('Togo');
        $marketTogo->setCodeIso3('TGO');

        $managerBenin = new User();
        $managerBenin->setRole(UserRoleEnum::PFT);
        $managerBenin->setMarket($marketBenin);
        $this->setUserWithId($managerBenin, 10);

        $agentTogo = new User();
        $agentTogo->setRole(UserRoleEnum::EMETTEUR_TERRAIN);
        $agentTogo->setMarket($marketTogo);
        $this->setUserWithId($agentTogo, 30);

        $token = $this->createToken($managerBenin);

        // Manager cannot view, edit or delete agent from another market
        $this->assertEquals(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $agentTogo, [UserVoter::VIEW]));
        $this->assertEquals(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $agentTogo, [UserVoter::EDIT]));
        $this->assertEquals(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $agentTogo, [UserVoter::DELETE]));
    }

    public function testManagerCannotManageOtherManagerOrAdmin(): void
    {
        $marketBenin = new Market();
        $marketBenin->setNom('Bénin');

        $manager1 = new User();
        $manager1->setRole(UserRoleEnum::PFT);
        $manager1->setMarket($marketBenin);
        $this->setUserWithId($manager1, 10);

        $manager2 = new User();
        $manager2->setRole(UserRoleEnum::PFT);
        $manager2->setMarket($marketBenin);
        $this->setUserWithId($manager2, 11);

        $admin = new User();
        $admin->setRole(UserRoleEnum::SUPERADMIN);
        $this->setUserWithId($admin, 1);

        $token = $this->createToken($manager1);

        // Cannot edit or delete other managers or admins
        $this->assertEquals(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $manager2, [UserVoter::EDIT]));
        $this->assertEquals(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $manager2, [UserVoter::DELETE]));
        $this->assertEquals(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $admin, [UserVoter::EDIT]));
        $this->assertEquals(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $admin, [UserVoter::DELETE]));
    }

    public function testAgentCannotManageAnyUser(): void
    {
        $agent = new User();
        $agent->setRole(UserRoleEnum::EMETTEUR_TERRAIN);
        $this->setUserWithId($agent, 50);

        $otherAgent = new User();
        $otherAgent->setRole(UserRoleEnum::EMETTEUR_TERRAIN);
        $this->setUserWithId($otherAgent, 51);

        $token = $this->createToken($agent);

        $this->assertEquals(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $otherAgent, [UserVoter::VIEW]));
        $this->assertEquals(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, null, [UserVoter::CREATE]));
        $this->assertEquals(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $otherAgent, [UserVoter::EDIT]));
        $this->assertEquals(VoterInterface::ACCESS_DENIED, $this->voter->vote($token, $otherAgent, [UserVoter::DELETE]));
    }
}
