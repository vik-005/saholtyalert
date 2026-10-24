<?php

namespace App\Service;

use App\Entity\PasswordResetToken;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PasswordResetManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {}

    public function issue(User $user): string
    {
        $this->removeTokensFor($user);

        $token = bin2hex(random_bytes(32));
        $this->entityManager->persist(new PasswordResetToken(
            $user,
            hash('sha256', $token),
            new \DateTimeImmutable('+1 hour'),
        ));
        $this->entityManager->flush();

        return $token;
    }

    public function reset(string $token, string $newPassword): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return false;
        }

        $resetToken = $this->entityManager->getRepository(PasswordResetToken::class)
            ->findOneBy(['tokenHash' => hash('sha256', $token)]);

        if (!$resetToken instanceof PasswordResetToken) {
            return false;
        }

        $user = $resetToken->getUser();
        if ($resetToken->isExpired() || !$user->isActif()) {
            $this->entityManager->remove($resetToken);
            $this->entityManager->flush();
            return false;
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $newPassword));
        $this->removeTokensFor($user);
        $this->entityManager->flush();

        return true;
    }

    public function revoke(User $user): void
    {
        $this->removeTokensFor($user);
        $this->entityManager->flush();
    }

    private function removeTokensFor(User $user): void
    {
        $this->entityManager->createQuery(
            'DELETE FROM App\\Entity\\PasswordResetToken token WHERE token.user = :user'
        )->setParameter('user', $user)->execute();
    }
}