<?php

namespace App\Voter;

use App\Entity\Source;
use App\Entity\User;
use App\Enum\UserRoleEnum;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class SourceVoter extends Voter
{
    public const VIEW_IDENTITY = 'SOURCE_VIEW_IDENTITY';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::VIEW_IDENTITY && ($subject instanceof Source || $subject === null);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        // Seul le rôle SAHOLTY / SUPERADMIN avec 2FA activé peut voir l'identité brute
        return in_array($user->getRole(), [UserRoleEnum::SAHOLTY, UserRoleEnum::SUPERADMIN]) && $user->isTotpEnabled();
    }
}
