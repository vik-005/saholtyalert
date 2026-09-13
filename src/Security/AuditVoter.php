<?php

namespace App\Security;

use App\Entity\User;
use App\Enum\UserRoleEnum;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class AuditVoter extends Voter
{
    public const VIEW = 'AUDIT_VIEW';
    public const EXPORT = 'AUDIT_EXPORT';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EXPORT], true) && $subject === null;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        return match ($attribute) {
            self::VIEW => $this->canView($user),
            self::EXPORT => $this->canExport($user),
            default => false,
        };
    }

    private function canView(User $user): bool
    {
        return match ($user->getRole()) {
            UserRoleEnum::SUPERADMIN,
            UserRoleEnum::SAHOLTY,
            UserRoleEnum::PFT,
            UserRoleEnum::SECRETARIAT_GEI,
            UserRoleEnum::COMITE_AIT => true,
            default => false,
        };
    }

    private function canExport(User $user): bool
    {
        return match ($user->getRole()) {
            UserRoleEnum::SUPERADMIN,
            UserRoleEnum::SAHOLTY,
            UserRoleEnum::PFT,
            UserRoleEnum::SECRETARIAT_GEI => true,
            default => false,
        };
    }
}
