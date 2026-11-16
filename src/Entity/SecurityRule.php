<?php

namespace App\Entity;

use App\Repository\SecurityRuleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Règles de détection des intrusions.
 * Chaque règle décrit un pattern de comportement à détecter.
 * Permet la configuration flexible des seuils de détection.
 */
#[ORM\Entity(repositoryClass: SecurityRuleRepository::class)]
#[ORM\Table(name: 'security_rule')]
#[ORM\Index(columns: ['rule_type'], name: 'idx_security_rule_type')]
#[ORM\Index(columns: ['is_enabled'], name: 'idx_security_rule_enabled')]
class SecurityRule
{
    // Types de règles
    public const TYPE_BRUTE_FORCE = 'brute_force';
    public const TYPE_SCANNING = 'scanning';
    public const TYPE_AUTOMATED_REQUEST = 'automated_request';
    public const TYPE_GEOGRAPHIC_ANOMALY = 'geographic_anomaly';
    public const TYPE_PERMISSION_ESCALATION = 'permission_escalation';
    public const TYPE_SENSITIVE_RESOURCE = 'sensitive_resource';
    public const TYPE_RATE_LIMITING = 'rate_limiting';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    /** Type de règle */
    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $ruleType;

    /** Nom descriptif */
    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $name;

    /** Description */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** Actif/inactif */
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isEnabled = true;

    /** Seuil : nombre d'événements */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $thresholdCount = null;

    /** Seuil : période de temps en secondes */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $thresholdTimeSeconds = null;

    /** Seuil : score de risque minimum pour déclencher */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $thresholdRiskScore = null;

    /** Score de confiance si règle déclenchée (0-100) */
    #[ORM\Column(type: Types::INTEGER)]
    private int $confidenceScore = 80;

    /** Sévérité de l'alerte générée */
    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $alertSeverity = SecurityAlert::SEVERITY_MEDIUM;

    /** Actions recommandées en JSON */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $recommendedActions = null;

    /** Critères de filtre en JSON (ex: {"event_types": ["login_failed"], "routes": ["/admin"]}) */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $filterCriteria = null;

    /** Conditions avancées en JSON */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $conditions = null;

    /** Créé par l'administrateur */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdByAdmin = null;

    /** Créé à */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

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
    public function getRuleType(): string { return $this->ruleType; }
    public function setRuleType(string $ruleType): self { $this->ruleType = $ruleType; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): self { $this->description = $description; return $this; }
    public function isEnabled(): bool { return $this->isEnabled; }
    public function setIsEnabled(bool $isEnabled): self { $this->isEnabled = $isEnabled; return $this; }
    public function getThresholdCount(): ?int { return $this->thresholdCount; }
    public function setThresholdCount(?int $thresholdCount): self { $this->thresholdCount = $thresholdCount; return $this; }
    public function getThresholdTimeSeconds(): ?int { return $this->thresholdTimeSeconds; }
    public function setThresholdTimeSeconds(?int $thresholdTimeSeconds): self { $this->thresholdTimeSeconds = $thresholdTimeSeconds; return $this; }
    public function getThresholdRiskScore(): ?int { return $this->thresholdRiskScore; }
    public function setThresholdRiskScore(?int $thresholdRiskScore): self { $this->thresholdRiskScore = $thresholdRiskScore; return $this; }
    public function getConfidenceScore(): int { return $this->confidenceScore; }
    public function setConfidenceScore(int $confidenceScore): self { $this->confidenceScore = max(0, min(100, $confidenceScore)); return $this; }
    public function getAlertSeverity(): string { return $this->alertSeverity; }
    public function setAlertSeverity(string $alertSeverity): self { $this->alertSeverity = $alertSeverity; return $this; }
    public function getRecommendedActions(): ?array { return $this->recommendedActions; }
    public function setRecommendedActions(?array $recommendedActions): self { $this->recommendedActions = $recommendedActions; return $this; }
    public function getFilterCriteria(): ?array { return $this->filterCriteria; }
    public function setFilterCriteria(?array $filterCriteria): self { $this->filterCriteria = $filterCriteria; return $this; }
    public function getConditions(): ?array { return $this->conditions; }
    public function setConditions(?array $conditions): self { $this->conditions = $conditions; return $this; }
    public function getCreatedByAdmin(): ?User { return $this->createdByAdmin; }
    public function setCreatedByAdmin(?User $createdByAdmin): self { $this->createdByAdmin = $createdByAdmin; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeImmutable $updatedAt): self { $this->updatedAt = $updatedAt; return $this; }
}
