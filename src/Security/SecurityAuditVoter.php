<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class SecurityAuditVoter extends Voter
{
    public const VIEW_AUDIT = 'SECURITY_AUDIT_VIEW';
    public const MANAGE_BLOCKS = 'SECURITY_MANAGE_BLOCKS';
    public const VIEW_ALERTS = 'SECURITY_VIEW_ALERTS';
    public const MANAGE_ALERTS = 'SECURITY_MANAGE_ALERTS';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [
            self::VIEW_AUDIT,
            self::MANAGE_BLOCKS,
            self::VIEW_ALERTS,
            self::MANAGE_ALERTS,
        ], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        return match ($attribute) {
            self::VIEW_AUDIT => $this->canViewAudit($user),
            self::MANAGE_BLOCKS => $this->canManageBlocks($user),
            self::VIEW_ALERTS => $this->canViewAlerts($user),
            self::MANAGE_ALERTS => $this->canManageAlerts($user),
            default => false,
        };
    }

    private function canViewAudit(User $user): bool
    {
        return in_array($user->getRole()->value, [
            'superadmin',
            'saholty',
            'secretariat_gei',
        ], true);
    }

    private function canManageBlocks(User $user): bool
    {
        return $user->getRole()->value === 'superadmin';
    }

    private function canViewAlerts(User $user): bool
    {
        return in_array($user->getRole()->value, [
            'superadmin',
            'saholty',
            'secretariat_gei',
        ], true);
    }

    private function canManageAlerts(User $user): bool
    {
        return $user->getRole()->value === 'superadmin';
    }
}