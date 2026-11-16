<?php

namespace App\Entity;

use App\Repository\SecurityInvestigationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Investigations de sécurité.
 * Dossier d'investigation créé pour tracker les événements suspects groupés.
 * Permet de suivre la timeline complète d'une tentative d'intrusion.
 */
#[ORM\Entity(repositoryClass: SecurityInvestigationRepository::class)]
#[ORM\Table(name: 'security_investigation')]
#[ORM\Index(columns: ['status'], name: 'idx_security_investigation_status')]
#[ORM\Index(columns: ['ip_address'], name: 'idx_security_investigation_ip')]
#[ORM\Index(columns: ['created_at'], name: 'idx_security_investigation_created')]
class SecurityInvestigation
{
    // Statuts
    public const STATUS_OPEN = 'open';
    public const STATUS_INVESTIGATING = 'investigating';
    public const STATUS_ESCALATED = 'escalated';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_THREAT_CONFIRMED = 'threat_confirmed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    /** Titre */
    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $title;

    /** Description de l'incident */
    #[ORM\Column(type: Types::TEXT)]
    private string $description;

    /** Statut actuel */
    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $status = self::STATUS_OPEN;

    /** Adresse IP primaire impliquée */
    #[ORM\Column(type: Types::STRING, length: 45, nullable: true)]
    private ?string $ipAddress = null;

    /** Utilisateur impliqué */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    /** Lien vers l'alerte de sécurité */
    #[ORM\ManyToOne(targetEntity: SecurityAlert::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?SecurityAlert $securityAlert = null;

    /** Type d'incident */
    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    private ?string $incidentType = null;

    /** Niveau de sévérité */
    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $severity = 'medium';

    /** Administrateur enquêteur */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $investigatedByAdmin = null;

    /** Conclusions */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $conclusions = null;

    /** Actions prises */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $actionsTaken = null;

    /** Timeline d'événements en JSON */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $eventTimeline = null;

    /** Preuves collectées en JSON */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $evidence = null;

    /** Chiffres clés en JSON (nombre de tentatives, durée, etc.) */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $metrics = null;

    /** Créé à */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** Ouvert à */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $openedAt = null;

    /** Fermé à */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    /** Mis à jour à */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int { return $this->id; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): self { $this->title = $title; return $this; }
    public function getDescription(): string { return $this->description; }
    public function setDescription(string $description): self { $this->description = $description; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }
    public function getIpAddress(): ?string { return $this->ipAddress; }
    public function setIpAddress(?string $ipAddress): self { $this->ipAddress = $ipAddress; return $this; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): self { $this->user = $user; return $this; }
    public function getSecurityAlert(): ?SecurityAlert { return $this->securityAlert; }
    public function setSecurityAlert(?SecurityAlert $securityAlert): self { $this->securityAlert = $securityAlert; return $this; }
    public function getIncidentType(): ?string { return $this->incidentType; }
    public function setIncidentType(?string $incidentType): self { $this->incidentType = $incidentType; return $this; }
    public function getSeverity(): string { return $this->severity; }
    public function setSeverity(string $severity): self { $this->severity = $severity; return $this; }
    public function getInvestigatedByAdmin(): ?User { return $this->investigatedByAdmin; }
    public function setInvestigatedByAdmin(?User $investigatedByAdmin): self { $this->investigatedByAdmin = $investigatedByAdmin; return $this; }
    public function getConclusions(): ?string { return $this->conclusions; }
    public function setConclusions(?string $conclusions): self { $this->conclusions = $conclusions; return $this; }
    public function getActionsTaken(): ?string { return $this->actionsTaken; }
    public function setActionsTaken(?string $actionsTaken): self { $this->actionsTaken = $actionsTaken; return $this; }
    public function getEventTimeline(): ?array { return $this->eventTimeline; }
    public function setEventTimeline(?array $eventTimeline): self { $this->eventTimeline = $eventTimeline; return $this; }
    public function getEvidence(): ?array { return $this->evidence; }
    public function setEvidence(?array $evidence): self { $this->evidence = $evidence; return $this; }
    public function getMetrics(): ?array { return $this->metrics; }
    public function setMetrics(?array $metrics): self { $this->metrics = $metrics; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getOpenedAt(): ?\DateTimeImmutable { return $this->openedAt; }
    public function setOpenedAt(?\DateTimeImmutable $openedAt): self { $this->openedAt = $openedAt; return $this; }
    public function getClosedAt(): ?\DateTimeImmutable { return $this->closedAt; }
    public function setClosedAt(?\DateTimeImmutable $closedAt): self { $this->closedAt = $closedAt; return $this; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeImmutable $updatedAt): self { $this->updatedAt = $updatedAt; return $this; }
}
