<?php

namespace App\Entity;

use App\Repository\IpAddressReputationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Réputation des adresses IP.
 * Cache les données Threat Intelligence (IPinfo, AbuseIPDB, MaxMind).
 * Met à jour automatiquement selon TTL configurable.
 */
#[ORM\Entity(repositoryClass: IpAddressReputationRepository::class)]
#[ORM\Table(name: 'ip_address_reputation')]
#[ORM\Index(columns: ['ip_address'], name: 'idx_ip_reputation_address')]
#[ORM\Index(columns: ['last_checked_at'], name: 'idx_ip_reputation_checked')]
#[ORM\Index(columns: ['risk_score'], name: 'idx_ip_reputation_risk')]
#[ORM\Index(columns: ['is_vpn', 'is_proxy', 'is_tor'], name: 'idx_ip_reputation_anonymized')]
class IpAddressReputation
{
    public const TTL_CACHE = 86400; // 24 heures

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    /** Adresse IP (IPv4 ou IPv6) */
    #[ORM\Column(type: Types::STRING, length: 45, unique: true)]
    private string $ipAddress;

    /** Version IP (4 ou 6) */
    #[ORM\Column(type: Types::INTEGER)]
    private int $ipVersion = 4;

    /** Pays estimé */
    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    private ?string $country = null;

    /** Code ISO 2 */
    #[ORM\Column(type: Types::STRING, length: 2, nullable: true)]
    private ?string $countryCode = null;

    /** Région */
    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    private ?string $region = null;

    /** Ville */
    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    private ?string $city = null;

    /** Latitude */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 6, nullable: true)]
    private ?string $latitude = null;

    /** Longitude */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 6, nullable: true)]
    private ?string $longitude = null;

    /** Fournisseur réseau (ISP) */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $isp = null;

    /** Numéro AS (Autonomous System) */
    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $asn = null;

    /** Organisation propriétaire */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $organization = null;

    /** Type de réseau : residential, mobile, hosting, vpn, proxy, tor, datacenter */
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

    /** Score de risque AbuseIPDB (0-100) */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $abuseIpDbScore = null;

    /** Nombre de reports sur AbuseIPDB */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $abuseIpDbReportCount = null;

    /** VirusTotal détections count */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $virusTotalDetections = null;

    /** Score de risque synthétisé (0-100) basé sur toutes les sources */
    #[ORM\Column(type: Types::INTEGER)]
    private int $riskScore = 0;

    /** Catégories de risque en JSON : ["abuse", "malware", "phishing", "spam"] */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $riskCategories = null;

    /** Données brutes en JSON pour audit trail */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $rawData = null;

    /** Sources vérifiées : ipinfo, abuseipdb, maxmind, virustotal */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $verifiedSources = null;

    /** Dernière vérification */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $lastCheckedAt;

    /** Créé à */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** Mis à jour à */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->lastCheckedAt = $now;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int { return $this->id; }
    public function getIpAddress(): string { return $this->ipAddress; }
    public function setIpAddress(string $ipAddress): self { $this->ipAddress = $ipAddress; return $this; }
    public function getIpVersion(): int { return $this->ipVersion; }
    public function setIpVersion(int $ipVersion): self { $this->ipVersion = $ipVersion; return $this; }
    public function getCountry(): ?string { return $this->country; }
    public function setCountry(?string $country): self { $this->country = $country; return $this; }
    public function getCountryCode(): ?string { return $this->countryCode; }
    public function setCountryCode(?string $countryCode): self { $this->countryCode = $countryCode; return $this; }
    public function getRegion(): ?string { return $this->region; }
    public function setRegion(?string $region): self { $this->region = $region; return $this; }
    public function getCity(): ?string { return $this->city; }
    public function setCity(?string $city): self { $this->city = $city; return $this; }
    public function getLatitude(): ?string { return $this->latitude; }
    public function setLatitude(?string $latitude): self { $this->latitude = $latitude; return $this; }
    public function getLongitude(): ?string { return $this->longitude; }
    public function setLongitude(?string $longitude): self { $this->longitude = $longitude; return $this; }
    public function getIsp(): ?string { return $this->isp; }
    public function setIsp(?string $isp): self { $this->isp = $isp; return $this; }
    public function getAsn(): ?string { return $this->asn; }
    public function setAsn(?string $asn): self { $this->asn = $asn; return $this; }
    public function getOrganization(): ?string { return $this->organization; }
    public function setOrganization(?string $organization): self { $this->organization = $organization; return $this; }
    public function getNetworkType(): ?string { return $this->networkType; }
    public function setNetworkType(?string $networkType): self { $this->networkType = $networkType; return $this; }
    public function isVpn(): bool { return $this->isVpn; }
    public function setIsVpn(bool $isVpn): self { $this->isVpn = $isVpn; return $this; }
    public function isProxy(): bool { return $this->isProxy; }
    public function setIsProxy(bool $isProxy): self { $this->isProxy = $isProxy; return $this; }
    public function isTor(): bool { return $this->isTor; }
    public function setIsTor(bool $isTor): self { $this->isTor = $isTor; return $this; }
    public function getAbuseIpDbScore(): ?int { return $this->abuseIpDbScore; }
    public function setAbuseIpDbScore(?int $abuseIpDbScore): self { $this->abuseIpDbScore = $abuseIpDbScore; return $this; }
    public function getAbuseIpDbReportCount(): ?int { return $this->abuseIpDbReportCount; }
    public function setAbuseIpDbReportCount(?int $abuseIpDbReportCount): self { $this->abuseIpDbReportCount = $abuseIpDbReportCount; return $this; }
    public function getVirusTotalDetections(): ?int { return $this->virusTotalDetections; }
    public function setVirusTotalDetections(?int $virusTotalDetections): self { $this->virusTotalDetections = $virusTotalDetections; return $this; }
    public function getRiskScore(): int { return $this->riskScore; }
    public function setRiskScore(int $riskScore): self { $this->riskScore = max(0, min(100, $riskScore)); return $this; }
    public function getRiskCategories(): ?array { return $this->riskCategories; }
    public function setRiskCategories(?array $riskCategories): self { $this->riskCategories = $riskCategories; return $this; }
    public function getRawData(): ?array { return $this->rawData; }
    public function setRawData(?array $rawData): self { $this->rawData = $rawData; return $this; }
    public function getVerifiedSources(): ?array { return $this->verifiedSources; }
    public function setVerifiedSources(?array $verifiedSources): self { $this->verifiedSources = $verifiedSources; return $this; }
    public function getLastCheckedAt(): \DateTimeImmutable { return $this->lastCheckedAt; }
    public function setLastCheckedAt(\DateTimeImmutable $lastCheckedAt): self { $this->lastCheckedAt = $lastCheckedAt; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeImmutable $updatedAt): self { $this->updatedAt = $updatedAt; return $this; }

    public function isExpired(): bool
    {
        $expiresAt = $this->lastCheckedAt->modify('+' . self::TTL_CACHE . ' seconds');
        return $expiresAt < new \DateTimeImmutable();
    }

    public function isAnonymized(): bool
    {
        return $this->isVpn || $this->isProxy || $this->isTor;
    }
}
