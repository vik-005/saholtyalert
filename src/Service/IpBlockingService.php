<?php

namespace App\Service;

use App\Entity\IpBlocklist;
use App\Entity\User;
use App\Repository\IpBlocklistRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class IpBlockingService
{
    public function __construct(
        private readonly IpBlocklistRepository $blocklistRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {}

    public function isIpBlocked(string $ipAddress): bool
    {
        return $this->blocklistRepository->isIpBlocked($ipAddress);
    }

    public function isIpWhitelisted(string $ipAddress): bool
    {
        return $this->blocklistRepository->isIpWhitelisted($ipAddress);
    }

    public function blockIp(string $ipAddress, string $reason, ?User $admin = null, ?string $description = null, ?int $durationDays = null, ?int $riskScore = null): IpBlocklist
    {
        $existing = $this->blocklistRepository->findByIpAddress($ipAddress);
        if ($existing !== null && $existing->isActive()) {
            return $existing;
        }

        $blocklist = new IpBlocklist();
        $blocklist->setIpAddress($ipAddress);
        $blocklist->setReason($reason);
        $blocklist->setDescription($description);
        $blocklist->setBlockedByAdmin($admin);
        $blocklist->setIsWhitelist(false);
        $blocklist->setIsActive(true);
        $blocklist->setRiskScoreTrigger($riskScore);

        $durationDays ??= IpBlocklist::DEFAULT_DURATION_DAYS;
        if ($durationDays > 0) {
            $blocklist->setExpiresAt((new \DateTimeImmutable())->modify("+{$durationDays} days"));
        }

        $this->entityManager->persist($blocklist);
        $this->entityManager->flush();
        $this->logger->info('IP blocked', ['ip' => $ipAddress, 'reason' => $reason]);

        return $blocklist;
    }

    public function unblockIp(string $ipAddress, ?User $admin = null, ?string $reason = null): ?IpBlocklist
    {
        $blocklist = $this->blocklistRepository->findByIpAddress($ipAddress);
        if ($blocklist === null || !$blocklist->isActive()) {
            return null;
        }

        $blocklist->setIsActive(false);
        $blocklist->setUnblockingAt(new \DateTimeImmutable());
        $blocklist->setUnblockingAdmin($admin);
        $blocklist->setUnblockingReason($reason);
        $this->entityManager->flush();

        return $blocklist;
    }

    public function addToWhitelist(string $ipAddress, ?User $admin = null, ?string $description = null): IpBlocklist
    {
        $existing = $this->blocklistRepository->findByIpAddress($ipAddress);
        if ($existing !== null) {
            $existing->setIsWhitelist(true);
            $existing->setIsActive(true);
            $this->entityManager->flush();
            return $existing;
        }

        $blocklist = new IpBlocklist();
        $blocklist->setIpAddress($ipAddress);
        $blocklist->setReason('manual');
        $blocklist->setDescription($description);
        $blocklist->setBlockedByAdmin($admin);
        $blocklist->setIsWhitelist(true);
        $blocklist->setIsActive(true);

        $this->entityManager->persist($blocklist);
        $this->entityManager->flush();

        return $blocklist;
    }

    public function removeFromWhitelist(string $ipAddress): ?IpBlocklist
    {
        $blocklist = $this->blocklistRepository->findByIpAddress($ipAddress);
        if ($blocklist === null || !$blocklist->isWhitelist()) {
            return null;
        }

        $blocklist->setIsActive(false);
        $this->entityManager->flush();

        return $blocklist;
    }

    public function getActiveBlocks(): array
    {
        return $this->blocklistRepository->findActiveBlocks();
    }

    public function getActiveWhitelist(): array
    {
        return $this->blocklistRepository->findActiveWhitelist();
    }

    public function cleanupExpired(): int
    {
        $expired = $this->blocklistRepository->findExpiredEntries();
        $count = 0;
        foreach ($expired as $entry) {
            $entry->setIsActive(false);
            $count++;
        }
        $this->entityManager->flush();
        return $count;
    }
}