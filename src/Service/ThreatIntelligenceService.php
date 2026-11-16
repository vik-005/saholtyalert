<?php

namespace App\Service;

use App\Entity\IpAddressReputation;
use App\Repository\IpAddressReputationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Yaml\Yaml;

class ThreatIntelligenceService
{
    private ?array $config = null;

    public function __construct(
        private readonly IpAddressReputationRepository $reputationRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
        $this->loadConfig();
    }

    private function loadConfig(): void
    {
        $defaults = [
            'threat_intel' => [
                'enabled' => false,
                'ipinfo_token' => null,
                'abuseipdb_key' => null,
                'maxmind_license' => null,
            ],
        ];

        $configPath = dirname(__DIR__, 2) . '/config/packages/security_audit.yaml';
        if (!file_exists($configPath)) {
            $this->config = $defaults;
            return;
        }

        $raw = Yaml::parseFile($configPath);

        // Support both the legacy top-level 'threat_intel' key and the current
        // 'parameters' structure (keys prefixed with 'security_audit.threat_intel.*').
        if (isset($raw['threat_intel'])) {
            $this->config = $raw;
        } elseif (isset($raw['parameters'])) {
            $params = $raw['parameters'];
            $this->config = [
                'threat_intel' => [
                    'enabled'          => $params['security_audit.threat_intel.enabled'] ?? false,
                    'ipinfo_token'     => $params['security_audit.threat_intel.ipinfo_token'] ?? null,
                    'abuseipdb_key'    => $params['security_audit.threat_intel.abuseipdb_key'] ?? null,
                    'maxmind_license'  => $params['security_audit.threat_intel.maxmind_license'] ?? null,
                ],
            ];
        } else {
            $this->config = $defaults;
        }
    }

    public function enrichIpData(string $ipAddress): IpAddressReputation
    {
        $reputation = $this->reputationRepository->findOrCreate($ipAddress);
        
        // Cache check
        $cacheKey = "threat_intel_{$ipAddress}";
        $cached = $this->cache->get($cacheKey, function () use ($reputation) {
            $this->fetchAllData($reputation);
            return true;
        }, is_variation: $this->config['threat_intel']['enabled'] ?? false ? 3600 : 0);

        return $reputation;
    }

    private function fetchAllData(IpAddressReputation $reputation): void
    {
        $ipAddress = $reputation->getIpAddress();

        // IPInfo
        if (!empty($this->config['threat_intel']['ipinfo_token'])) {
            $this->fetchIpInfo($reputation);
        }

        // AbuseIPDB
        if (!empty($this->config['threat_intel']['abuseipdb_key'])) {
            $this->fetchAbuseIpDb($reputation);
        }

        // MaxMind
        if (!empty($this->config['threat_intel']['maxmind_license'])) {
            $this->fetchMaxMind($reputation);
        }

        $reputation->setLastCheckedAt(new \DateTimeImmutable());
        $reputation->setUpdatedAt(new \DateTimeImmutable());
        $reputation->setVerifiedSources(array_filter([
            !empty($this->config['threat_intel']['ipinfo_token']) ? 'ipinfo' : null,
            !empty($this->config['threat_intel']['abuseipdb_key']) ? 'abuseipdb' : null,
            !empty($this->config['threat_intel']['maxmind_license']) ? 'maxmind' : null,
        ]));

        $this->entityManager->flush();
    }

    private function fetchIpInfo(IpAddressReputation $reputation): void
    {
        try {
            $client = HttpClient::create();
            $response = $client->request('GET', "https://ipinfo.io/{$reputation->getIpAddress()}/json", [
                'headers' => ['Authorization' => "Bearer {$this->config['threat_intel']['ipinfo_token']}"],
            ]);

            if ($response->getStatusCode() === 200) {
                $data = $response->toArray();
                
                if (isset($data['country'])) {
                    $reputation->setCountry($data['country']);
                    $reputation->setCountryCode($data['country']);
                }
                if (isset($data['region'])) {
                    $reputation->setRegion($data['region']);
                }
                if (isset($data['city'])) {
                    $reputation->setCity($data['city']);
                }
                if (isset($data['org'])) {
                    $reputation->setOrganization($data['org']);
                    // Parse ISP from org
                    $parts = explode(' ', $data['org'], 2);
                    if (count($parts) === 2) {
                        $reputation->setAsn($parts[0]);
                        $reputation->setIsp($parts[1]);
                    }
                }
                if (isset($data['privacy']['vpn']) || isset($data['privacy']['proxy'])) {
                    $reputation->setIsVpn($data['privacy']['vpn'] ?? false);
                    $reputation->setIsProxy($data['privacy']['proxy'] ?? false);
                }
                if (isset($data['privacy']['tor'])) {
                    $reputation->setIsTor($data['privacy']['tor']);
                }

                $reputation->setRawData(array_merge($reputation->getRawData() ?: [], ['ipinfo' => $data]));
            }
        } catch (\Exception $e) {
            $this->logger->warning('IPInfo lookup failed', ['ip' => $reputation->getIpAddress(), 'error' => $e->getMessage()]);
        }
    }

    private function fetchAbuseIpDb(IpAddressReputation $reputation): void
    {
        try {
            $client = HttpClient::create();
            $response = $client->request('GET', "https://api.abuseipdb.com/api/v2/check", [
                'query' => [
                    'ipAddress' => $reputation->getIpAddress(),
                    'maxAgeInDays' => 90,
                    'verbose' => '',
                ],
                'headers' => [
                    'Key' => $this->config['threat_intel']['abuseipdb_key'],
                    'Accept' => 'application/json',
                ],
            ]);

            if ($response->getStatusCode() === 200) {
                $data = $response->toArray()['data'] ?? [];
                
                if (isset($data['abuseConfidenceScore'])) {
                    $reputation->setAbuseIpDbScore($data['abuseConfidenceScore']);
                }
                if (isset($data['numDistinctReports'])) {
                    $reputation->setAbuseIpDbReportCount($data['numDistinctReports']);
                }
                if (isset($data['isTor'])) {
                    $reputation->setIsTor($data['isTor']);
                }
                if (isset($data['isProxy'])) {
                    $reputation->setIsProxy($data['isProxy']);
                }
                if (isset($data['isVPN'])) {
                    $reputation->setIsVpn($data['isVPN']);
                }
                if (isset($data['isp'])) {
                    $reputation->setIsp($data['isp']);
                }
                if (isset($data['asn'])) {
                    $reputation->setAsn($data['asn']);
                }
                if (isset($data['countryCode'])) {
                    $reputation->setCountryCode($data['countryCode']);
                }

                $reputation->setRawData(array_merge($reputation->getRawData() ?: [], ['abuseipdb' => $data]));
            }
        } catch (\Exception $e) {
            $this->logger->warning('AbuseIPDB lookup failed', ['ip' => $reputation->getIpAddress(), 'error' => $e->getMessage()]);
        }
    }

    private function fetchMaxMind(IpAddressReputation $reputation): void
    {
        // MaxMind requires GeoIP2 database - simplified implementation
        // In production, use geoip2/geoip2 package with local database
        $this->logger->info('MaxMind lookup not fully implemented - requires local database');
    }

    public function isThreatIntelEnabled(): bool
    {
        return $this->config['threat_intel']['enabled'] ?? false;
    }

    public function getConfig(): array
    {
        return $this->config['threat_intel'] ?? [];
    }
}