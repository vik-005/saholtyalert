<?php

namespace App\Service;

use App\Entity\IpAddressReputation;
use App\Repository\IpAddressReputationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service de gestion de la réputation des adresses IP.
 * Intègre les services Threat Intelligence externes.
 * Cache les résultats pour éviter les appels répétés.
 */
class IpReputationService
{
    public function __construct(
        private readonly IpAddressReputationRepository $reputationRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Obtient ou crée une réputation IP
     */
    public function getOrCreateReputation(string $ipAddress): IpAddressReputation
    {
        $reputation = $this->reputationRepository->findByIpAddress($ipAddress);

        if ($reputation === null) {
            $reputation = new IpAddressReputation();
            $reputation->setIpAddress($ipAddress);
            $reputation->setIpVersion($this->detectIpVersion($ipAddress));
            $this->entityManager->persist($reputation);
            $this->entityManager->flush();
            return $reputation;
        }

        // Vérifier si mise à jour nécessaire (TTL expiré)
        if ($reputation->isExpired()) {
            $this->refreshReputation($reputation);
        }

        return $reputation;
    }

    /**
     * Rafraîchit les données de réputation d'une IP
     */
    public function refreshReputation(IpAddressReputation $reputation): void
    {
        $ipAddress = $reputation->getIpAddress();

        try {
            // Simuler des appels aux services externes
            // En production, intégrer IPinfo, AbuseIPDB, MaxMind API
            
            $now = new \DateTimeImmutable();
            $reputation->setLastCheckedAt($now);
            $reputation->setUpdatedAt($now);

            // Marquer comme vérifiée auprès des services (même si simulé)
            $reputation->setVerifiedSources(['simulated']);

            $this->entityManager->flush();
            
            $this->logger->info('IP reputation refreshed', ['ip' => $ipAddress]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to refresh IP reputation', [
                'ip' => $ipAddress,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Calcule le score de risque pour une IP
     */
    public function calculateRiskScore(IpAddressReputation $reputation): int
    {
        $score = 0;

        // AbuseIPDB score (0-100)
        if ($reputation->getAbuseIpDbScore() !== null) {
            $score += $reputation->getAbuseIpDbScore() * 0.5;
        }

        // VirusTotal detections
        if ($reputation->getVirusTotalDetections() !== null) {
            $score += min(30, $reputation->getVirusTotalDetections() * 2);
        }

        // Anonymisation
        if ($reputation->isTor()) {
            $score += 20;
        } elseif ($reputation->isVpn()) {
            $score += 10;
        } elseif ($reputation->isProxy()) {
            $score += 5;
        }

        // Datacenter
        if ($reputation->getNetworkType() === 'datacenter' || $reputation->getNetworkType() === 'hosting') {
            $score += 5;
        }

        return min(100, (int) $score);
    }

    /**
     * Détecte la version IP (4 ou 6)
     */
    private function detectIpVersion(string $ipAddress): int
    {
        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return 6;
        }
        return 4;
    }

    /**
     * Vérifie si une IP est anonymisée
     */
    public function isAnonymized(IpAddressReputation $reputation): bool
    {
        return $reputation->isTor() || $reputation->isVpn() || $reputation->isProxy();
    }

    /**
     * Obtient un résumé de la réputation
     */
    public function getSummary(IpAddressReputation $reputation): array
    {
        return [
            'ip' => $reputation->getIpAddress(),
            'country' => $reputation->getCountry(),
            'region' => $reputation->getRegion(),
            'city' => $reputation->getCity(),
            'isp' => $reputation->getIsp(),
            'asn' => $reputation->getAsn(),
            'organization' => $reputation->getOrganization(),
            'networkType' => $reputation->getNetworkType(),
            'isVpn' => $reputation->isVpn(),
            'isProxy' => $reputation->isProxy(),
            'isTor' => $reputation->isTor(),
            'riskScore' => $this->calculateRiskScore($reputation),
            'lastCheckedAt' => $reputation->getLastCheckedAt(),
            'isExpired' => $reputation->isExpired(),
            'isAnonymized' => $this->isAnonymized($reputation),
        ];
    }
}
