<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Géolocalisation approximative par adresse IP (fallback quand l'API navigateur est refusée).
 * Utilise ipapi.co (gratuit, 1 000 req/jour). Pour un usage intensif en production,
 * prévoir un abonnement IPinfo ou une base MaxMind GeoLite2 locale.
 */
class GeoIPService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {}

    /**
     * Retourne lat/lon approximatifs à partir d'une IP.
     * Fallback : centre de l'Afrique de l'Ouest si l'API est indisponible ou l'IP est locale.
     *
     * @return array{latitude: float, longitude: float, country: string|null, city: string|null}
     */
    public function getLocationFromIP(string $ip): array
    {
        // IPs locales / privées → retourner fallback sans appel réseau
        if ($this->isPrivateIp($ip)) {
            return $this->fallback();
        }

        try {
            $response = $this->httpClient->request('GET', "https://ipapi.co/{$ip}/json/", [
                'timeout' => 3.0,
                'headers' => ['Accept' => 'application/json'],
            ]);

            $data = $response->toArray(false);

            if (!empty($data['latitude']) && !empty($data['longitude'])) {
                return [
                    'latitude'  => (float) $data['latitude'],
                    'longitude' => (float) $data['longitude'],
                    'country'   => $data['country_name'] ?? null,
                    'city'      => $data['city'] ?? null,
                ];
            }
        } catch (\Throwable) {
            // API indisponible — silencieux
        }

        return $this->fallback();
    }

    private function isPrivateIp(string $ip): bool
    {
        return in_array($ip, ['127.0.0.1', '::1', 'localhost'], true)
            || str_starts_with($ip, '192.168.')
            || str_starts_with($ip, '10.')
            || str_starts_with($ip, '172.');
    }

    private function fallback(): array
    {
        return [
            'latitude'  => 8.0,
            'longitude' => -1.0,
            'country'   => 'Afrique de l\'Ouest',
            'city'      => 'Région estimée',
        ];
    }
}
