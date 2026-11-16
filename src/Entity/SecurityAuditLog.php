<?php

namespace App\Entity;

use App\Repository\SecurityAuditLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Journal d'audit sécurité centralisé.
 * Enregistre TOUS les événements de sécurité importants : authentification, accès, modifications.
 * Immutable après création. Protection absolue : aucune modification/suppression possible.
 *
 * Champ clé : riskScore (0-100) pour chaque événement.
 */
#[ORM\Entity(repositoryClass: SecurityAuditLogRepository::class)]
#[ORM\Table(name: 'security_audit_log')]
#[ORM\Index(columns: ['event_type'], name: 'idx_security_audit_event_type')]
#[ORM\Index(columns: ['ip_address'], name: 'idx_security_audit_ip')]
#[ORM\Index(columns: ['user_id'], name: 'idx_security_audit_user')]
#[ORM\Index(columns: ['created_at'], name: 'idx_security_audit_created')]
#[ORM\Index(columns: ['risk_score'], name: 'idx_security_audit_risk')]
#[ORM\Index(columns: ['created_at', 'risk_score'], name: 'idx_security_audit_created_risk')]
#[ORM\Index(columns: ['ip_address', 'created_at'], name: 'idx_security_audit_ip_created')]
class SecurityAuditLog
{
    // Types d'événements
    public const EVENT_LOGIN_SUCCESS = 'login_success';
    public const EVENT_LOGIN_FAILED = 'login_failed';
    public const EVENT_LOGOUT = 'logout';
    public const EVENT_UNKNOWN_ACCOUNT = 'unknown_account';
    public const EVENT_FORBIDDEN_ACCESS = 'forbidden_access';
    public const EVENT_ROUTE_ACCESSED = 'route_accessed';
    public const EVENT_ROUTE_NOT_FOUND = 'route_not_found';
    public const EVENT_HTTP_ERROR = 'http_error';
    public const EVENT_AUTOMATED_REQUEST = 'automated_request';
    public const EVENT_SENSITIVE_RESOURCE = 'sensitive_resource';
    public const EVENT_DATA_CREATED = 'data_created';
    public const EVENT_DATA_MODIFIED = 'data_modified';
    public const EVENT_DATA_DELETED = 'data_deleted';
    public const EVENT_USER_DELETED = 'user_deleted';
    public const EVENT_ROLE_CHANGED = 'role_changed';
    public const EVENT_SECURITY_SETTING_CHANGED = 'security_setting_changed';
    public const EVENT_IP_BLOCKED = 'ip_blocked';
    public const EVENT_IP_UNBLOCKED = 'ip_unblocked';

    // Niveaux de risque
    public const RISK_NORMAL = 'normal';          // 0-30
    public const RISK_SUSPICIOUS = 'suspicious';  // 31-60
    public const RISK_PROBABLE = 'probable';      // 61-80
    public const RISK_THREAT = 'threat';          // 81-100

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    /** Identifiant unique UUID pour corrélation entre systèmes */
    #[ORM\Column(type: Types::STRING, length: 36, unique: true)]
    private string $correlationId;

    /** Type d'événement (login_success, login_failed, etc.) */
    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $eventType;

    /** Utilisateur affecté (null si inconnu) */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    /** Adresse IP source (IPv4 ou IPv6) */
    #[ORM\Column(type: Types::STRING, length: 45)]
    private string $ipAddress;

    /** Route Symfony consultée (ex: app_dashboard) */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $routeName = null;

    /** URL complète demandée */
    #[ORM\Column(type: Types::STRING, length: 2048, nullable: true)]
    private ?string $urlPath = null;

    /** Méthode HTTP (GET, POST, PUT, DELETE, etc.) */
    #[ORM\Column(type: Types::STRING, length: 10, nullable: true)]
    private ?string $httpMethod = null;

    /** Code de réponse HTTP (200, 403, 404, 500, etc.) */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $httpStatusCode = null;

    /** User-Agent du client */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $userAgent = null;

    /** Navigateur estimé (Chrome, Firefox, Safari, Bot, etc.) */
    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    private ?string $browserName = null;

    /** Système d'exploitation estimé (Windows, Linux, macOS, iOS, Android, etc.) */
    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    private ?string $osName = null;

    /** Pays estimé par géolocalisation IP */
    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    private ?string $country = null;

    /** Région/État estimé */
    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    private ?string $region = null;

    /** Fournisseur réseau / ISP */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $isp = null;

    /** Numéro d'Autonomous System (ASN) */
    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $asn = null;

    /** Type de réseau : residential, mobile, hosting, vpn, proxy, tor */
    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $networkType = null;

    /** Détecté comme VPN */
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isVpn = false;

    /** Détecté comme Proxy */
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isProxy = false;

    /** Détecté comme Tor */
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isTor = false;

    /** Motif du rejet (si applicable) */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $rejectionReason = null;

    /** Score de risque (0-100) */
    #[ORM\Column(type: Types::INTEGER)]
    private int $riskScore = 0;

    /** Niveau de risque (normal, suspicious, probable, threat) */
    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $riskLevel = self::RISK_NORMAL;

    /** Facteurs de risque en JSON {"factor": score_contribution, ...} */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $riskFactors = null;

    /** Détails structurés en JSON */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $details = null;

    /** Date/heure UTC de l'événement */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->correlationId = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
        $this->createdAt = new \DateTimeImmutable();
    }

    // Getters & Setters
    public function getId(): ?int { return $this->id; }
    public function getCorrelationId(): string { return $this->correlationId; }
    public function getEventType(): string { return $this->eventType; }
    public function setEventType(string $eventType): self { $this->eventType = $eventType; return $this; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): self { $this->user = $user; return $this; }
    public function getIpAddress(): string { return $this->ipAddress; }
    public function setIpAddress(string $ipAddress): self { $this->ipAddress = $ipAddress; return $this; }
    public function getRouteName(): ?string { return $this->routeName; }
    public function setRouteName(?string $routeName): self { $this->routeName = $routeName; return $this; }
    public function getUrlPath(): ?string { return $this->urlPath; }
    public function setUrlPath(?string $urlPath): self { $this->urlPath = $urlPath; return $this; }
    public function getHttpMethod(): ?string { return $this->httpMethod; }
    public function setHttpMethod(?string $httpMethod): self { $this->httpMethod = $httpMethod; return $this; }
    public function getHttpStatusCode(): ?int { return $this->httpStatusCode; }
    public function setHttpStatusCode(?int $httpStatusCode): self { $this->httpStatusCode = $httpStatusCode; return $this; }
    public function getUserAgent(): ?string { return $this->userAgent; }
    public function setUserAgent(?string $userAgent): self { $this->userAgent = $userAgent; return $this; }
    public function getBrowserName(): ?string { return $this->browserName; }
    public function setBrowserName(?string $browserName): self { $this->browserName = $browserName; return $this; }
    public function getOsName(): ?string { return $this->osName; }
    public function setOsName(?string $osName): self { $this->osName = $osName; return $this; }
    public function getCountry(): ?string { return $this->country; }
    public function setCountry(?string $country): self { $this->country = $country; return $this; }
    public function getRegion(): ?string { return $this->region; }
    public function setRegion(?string $region): self { $this->region = $region; return $this; }
    public function getIsp(): ?string { return $this->isp; }
    public function setIsp(?string $isp): self { $this->isp = $isp; return $this; }
    public function getAsn(): ?string { return $this->asn; }
    public function setAsn(?string $asn): self { $this->asn = $asn; return $this; }
    public function getNetworkType(): ?string { return $this->networkType; }
    public function setNetworkType(?string $networkType): self { $this->networkType = $networkType; return $this; }
    public function isVpn(): bool { return $this->isVpn; }
    public function setIsVpn(bool $isVpn): self { $this->isVpn = $isVpn; return $this; }
    public function isProxy(): bool { return $this->isProxy; }
    public function setIsProxy(bool $isProxy): self { $this->isProxy = $isProxy; return $this; }
    public function isTor(): bool { return $this->isTor; }
    public function setIsTor(bool $isTor): self { $this->isTor = $isTor; return $this; }
    public function getRejectionReason(): ?string { return $this->rejectionReason; }
    public function setRejectionReason(?string $rejectionReason): self { $this->rejectionReason = $rejectionReason; return $this; }
    public function getRiskScore(): int { return $this->riskScore; }
    public function setRiskScore(int $riskScore): self { $this->riskScore = max(0, min(100, $riskScore)); return $this; }
    public function getRiskLevel(): string { return $this->riskLevel; }
    public function setRiskLevel(string $riskLevel): self { $this->riskLevel = $riskLevel; return $this; }
    public function getRiskFactors(): ?array { return $this->riskFactors; }
    public function setRiskFactors(?array $riskFactors): self { $this->riskFactors = $riskFactors; return $this; }
    public function getDetails(): ?array { return $this->details; }
    public function setDetails(?array $details): self { $this->details = $details; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
