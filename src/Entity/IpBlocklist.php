<?php

namespace App\Entity;

use App\Repository\IpBlocklistRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Liste de blocage des adresses IP.
 * Support des listes blanches et noires.
 * Durée de blocage configurable avec expiration automatique.
 */
#[ORM\Entity(repositoryClass: IpBlocklistRepository::class)]
#[ORM\Table(name: 'ip_blocklist')]
#[ORM\Index(columns: ['ip_address'], name: 'idx_ip_blocklist_address')]
#[ORM\Index(columns: ['blocked_at'], name: 'idx_ip_blocklist_blocked')]
#[ORM\Index(columns: ['expires_at'], name: 'idx_ip_blocklist_expires')]
#[ORM\Index(columns: ['is_whitelist'], name: 'idx_ip_blocklist_whitelist')]
#[ORM\Index(columns: ['is_active'], name: 'idx_ip_blocklist_active')]
class IpBlocklist
{
    public const DEFAULT_DURATION_DAYS = 180; // 6 mois par défaut

    // Raisons de blocage
    public const REASON_BRUTE_FORCE = 'brute_force';
    public const REASON_SCANNING = 'scanning';
    public const REASON_AUTOMATED = 'automated';
    public const REASON_THREAT_INTEL = 'threat_intel';
    public const REASON_MANUAL = 'manual';
    public const REASON_SUSPICIOUS_BEHAVIOR = 'suspicious_behavior';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    /** Adresse IP bloquée */
    #[ORM\Column(type: Types::STRING, length: 45, unique: true)]
    private string $ipAddress;

    /** Est dans la liste blanche (true) ou noire (false) */
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isWhitelist = false;

    /** Est actuellement actif */
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isActive = true;

    /** Raison du blocage */
    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $reason;

    /** Description détaillée */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** Score de risque qui a déclenchné le blocage */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $riskScoreTrigger = null;

    /** Administrateur qui a effectué l'action */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $blockedByAdmin = null;

    /** Date du blocage */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $blockedAt;

    /** Date d'expiration automatique (null = permanent) */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    /** Date de déblocage manuel (null = non débloquée) */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $unblockingAt = null;

    /** Administrateur qui a débloqué */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $unblockingAdmin = null;

    /** Raison du déblocage */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $unblockingReason = null;

    /** Pays estimé de l'IP */
    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    private ?string $country = null;

    /** ASN */
    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $asn = null;

    /** ISP */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $isp = null;

    /** Nombre de tentatives depuis ce blocage */
    #[ORM\Column(type: Types::INTEGER)]
    private int $attemptsSinceBlocage = 0;

    /** Métadonnées supplémentaires en JSON */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $metadata = null;

    /** Créé à */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** Mis à jour à */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->blockedAt = $now;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int { return $this->id; }
    public function getIpAddress(): string { return $this->ipAddress; }
    public function setIpAddress(string $ipAddress): self { $this->ipAddress = $ipAddress; return $this; }
    public function isWhitelist(): bool { return $this->isWhitelist; }
    public function setIsWhitelist(bool $isWhitelist): self { $this->isWhitelist = $isWhitelist; return $this; }
    public function isActive(): bool { return $this->isActive && !$this->isExpired(); }
    public function setIsActive(bool $isActive): self { $this->isActive = $isActive; return $this; }
    public function getReason(): string { return $this->reason; }
    public function setReason(string $reason): self { $this->reason = $reason; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): self { $this->description = $description; return $this; }
    public function getRiskScoreTrigger(): ?int { return $this->riskScoreTrigger; }
    public function setRiskScoreTrigger(?int $riskScoreTrigger): self { $this->riskScoreTrigger = $riskScoreTrigger; return $this; }
    public function getBlockedByAdmin(): ?User { return $this->blockedByAdmin; }
    public function setBlockedByAdmin(?User $blockedByAdmin): self { $this->blockedByAdmin = $blockedByAdmin; return $this; }
    public function getBlockedAt(): \DateTimeImmutable { return $this->blockedAt; }
    public function getExpiresAt(): ?\DateTimeImmutable { return $this->expiresAt; }
    public function setExpiresAt(?\DateTimeImmutable $expiresAt): self { $this->expiresAt = $expiresAt; return $this; }
    public function getUnblockingAt(): ?\DateTimeImmutable { return $this->unblockingAt; }
    public function setUnblockingAt(?\DateTimeImmutable $unblockingAt): self { $this->unblockingAt = $unblockingAt; return $this; }
    public function getUnblockingAdmin(): ?User { return $this->unblockingAdmin; }
    public function setUnblockingAdmin(?User $unblockingAdmin): self { $this->unblockingAdmin = $unblockingAdmin; return $this; }
    public function getUnblockingReason(): ?string { return $this->unblockingReason; }
    public function setUnblockingReason(?string $unblockingReason): self { $this->unblockingReason = $unblockingReason; return $this; }
    public function getCountry(): ?string { return $this->country; }
    public function setCountry(?string $country): self { $this->country = $country; return $this; }
    public function getAsn(): ?string { return $this->asn; }
    public function setAsn(?string $asn): self { $this->asn = $asn; return $this; }
    public function getIsp(): ?string { return $this->isp; }
    public function setIsp(?string $isp): self { $this->isp = $isp; return $this; }
    public function getAttemptsSinceBlocage(): int { return $this->attemptsSinceBlocage; }
    public function setAttemptsSinceBlocage(int $attemptsSinceBlocage): self { $this->attemptsSinceBlocage = $attemptsSinceBlocage; return $this; }
    public function incrementAttempts(): self { $this->attemptsSinceBlocage++; return $this; }
    public function getMetadata(): ?array { return $this->metadata; }
    public function setMetadata(?array $metadata): self { $this->metadata = $metadata; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeImmutable $updatedAt): self { $this->updatedAt = $updatedAt; return $this; }

    public function isExpired(): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }
        return $this->expiresAt < new \DateTimeImmutable();
    }
}
