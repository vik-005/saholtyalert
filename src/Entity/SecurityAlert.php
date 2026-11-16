<?php

namespace App\Entity;

use App\Repository\SecurityAlertRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Alertes de sécurité détectées automatiquement.
 * Agrège les événements de sécurité pour alerter les administrateurs.
 * Catégories : brute_force, scanning, automated, suspicious_behavior, lateral_movement, unauthorized_access.
 */
#[ORM\Entity(repositoryClass: SecurityAlertRepository::class)]
#[ORM\Table(name: 'security_alert')]
#[ORM\Index(columns: ['alert_type'], name: 'idx_security_alert_type')]
#[ORM\Index(columns: ['severity'], name: 'idx_security_alert_severity')]
#[ORM\Index(columns: ['status'], name: 'idx_security_alert_status')]
#[ORM\Index(columns: ['created_at'], name: 'idx_security_alert_created')]
#[ORM\Index(columns: ['detected_at'], name: 'idx_security_alert_detected')]
class SecurityAlert
{
    // Types d'alertes
    public const ALERT_BRUTE_FORCE = 'brute_force';
    public const ALERT_SCANNING = 'scanning';
    public const ALERT_AUTOMATED = 'automated';
    public const ALERT_SUSPICIOUS_BEHAVIOR = 'suspicious_behavior';
    public const ALERT_LATERAL_MOVEMENT = 'lateral_movement';
    public const ALERT_UNAUTHORIZED_ACCESS = 'unauthorized_access';

    // Niveaux de sévérité
    public const SEVERITY_LOW = 'low';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_HIGH = 'high';
    public const SEVERITY_CRITICAL = 'critical';

    // Statuts
    public const STATUS_NEW = 'new';
    public const STATUS_ACKNOWLEDGED = 'acknowledged';
    public const STATUS_INVESTIGATING = 'investigating';
    public const STATUS_MITIGATED = 'mitigated';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_FALSE_POSITIVE = 'false_positive';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    /** Type d'alerte */
    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $alertType;

    /** Sévérité */
    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $severity = self::SEVERITY_MEDIUM;

    /** Statut */
    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $status = self::STATUS_NEW;

    /** Titre descriptif */
    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $title;

    /** Description détaillée */
    #[ORM\Column(type: Types::TEXT)]
    private string $description;

    /** Adresse IP impliquée */
    #[ORM\Column(type: Types::STRING, length: 45, nullable: true)]
    private ?string $ipAddress = null;

    /** Utilisateur impliqué */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    /** Route/ressource ciblée */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $targetResource = null;

    /** Score de confiance (0-100) */
    #[ORM\Column(type: Types::INTEGER)]
    private int $confidenceScore = 0;

    /** Nombre d'événements agrégés */
    #[ORM\Column(type: Types::INTEGER)]
    private int $eventCount = 1;

    /** Données structurées en JSON */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $details = null;

    /** Actions recommandées en JSON */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $recommendedActions = null;

    /** Administrateur qui a reconnu l'alerte */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $acknowledgedByAdmin = null;

    /** Moment de la reconnaissance */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $acknowledgedAt = null;

    /** Note/commentaire de l'administrateur */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $adminNotes = null;

    /** Moment de détection */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $detectedAt;

    /** Moment de résolution */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    /** Créé à */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** Mis à jour à */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->detectedAt = $now;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int { return $this->id; }
    public function getAlertType(): string { return $this->alertType; }
    public function setAlertType(string $alertType): self { $this->alertType = $alertType; return $this; }
    public function getSeverity(): string { return $this->severity; }
    public function setSeverity(string $severity): self { $this->severity = $severity; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): self { $this->title = $title; return $this; }
    public function getDescription(): string { return $this->description; }
    public function setDescription(string $description): self { $this->description = $description; return $this; }
    public function getIpAddress(): ?string { return $this->ipAddress; }
    public function setIpAddress(?string $ipAddress): self { $this->ipAddress = $ipAddress; return $this; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): self { $this->user = $user; return $this; }
    public function getTargetResource(): ?string { return $this->targetResource; }
    public function setTargetResource(?string $targetResource): self { $this->targetResource = $targetResource; return $this; }
    public function getConfidenceScore(): int { return $this->confidenceScore; }
    public function setConfidenceScore(int $confidenceScore): self { $this->confidenceScore = max(0, min(100, $confidenceScore)); return $this; }
    public function getEventCount(): int { return $this->eventCount; }
    public function setEventCount(int $eventCount): self { $this->eventCount = $eventCount; return $this; }
    public function incrementEventCount(): self { $this->eventCount++; return $this; }
    public function getDetails(): ?array { return $this->details; }
    public function setDetails(?array $details): self { $this->details = $details; return $this; }
    public function getRecommendedActions(): ?array { return $this->recommendedActions; }
    public function setRecommendedActions(?array $recommendedActions): self { $this->recommendedActions = $recommendedActions; return $this; }
    public function getAcknowledgedByAdmin(): ?User { return $this->acknowledgedByAdmin; }
    public function setAcknowledgedByAdmin(?User $acknowledgedByAdmin): self { $this->acknowledgedByAdmin = $acknowledgedByAdmin; return $this; }
    public function getAcknowledgedAt(): ?\DateTimeImmutable { return $this->acknowledgedAt; }
    public function setAcknowledgedAt(?\DateTimeImmutable $acknowledgedAt): self { $this->acknowledgedAt = $acknowledgedAt; return $this; }
    public function getAdminNotes(): ?string { return $this->adminNotes; }
    public function setAdminNotes(?string $adminNotes): self { $this->adminNotes = $adminNotes; return $this; }
    public function getDetectedAt(): \DateTimeImmutable { return $this->detectedAt; }
    public function getResolvedAt(): ?\DateTimeImmutable { return $this->resolvedAt; }
    public function setResolvedAt(?\DateTimeImmutable $resolvedAt): self { $this->resolvedAt = $resolvedAt; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeImmutable $updatedAt): self { $this->updatedAt = $updatedAt; return $this; }
}
