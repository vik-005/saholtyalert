<?php

namespace App\EventSubscriber;

use App\Entity\ConnectionPosition;
use App\Entity\User;
use App\Service\GeoIPService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;
use Symfony\Component\Security\Http\SecurityEvents;

/**
 * Enregistre la position géographique (approximative) de chaque connexion.
 *
 * Ordre de priorité :
 *   1. Coordonnées transmises par le JS (champs latitude/longitude dans le POST login)
 *   2. Lookup IP via GeoIPService (fallback automatique si formulaire sans GPS)
 *
 * Le consentement explicite au GPS est géré côté JS sur le formulaire de login :
 *   - Premier chargement  → demande permission navigateur
 *   - Permission accordée → champs lat/lon ajoutés au POST
 *   - Permission refusée  → le listener utilise le fallback IP (jamais de capture silencieuse)
 */
class ConnectionGeoSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly GeoIPService $geoIPService,
        private readonly EntityManagerInterface $em,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            SecurityEvents::INTERACTIVE_LOGIN => ['onLogin', 0],
        ];
    }

    public function onLogin(InteractiveLoginEvent $event): void
    {
        $user    = $event->getAuthenticationToken()->getUser();
        $request = $event->getRequest();

        if (!$user instanceof User) {
            return;
        }

        try {
            $position = new ConnectionPosition();
            $position->setUser($user);
            $position->setConnectedAt(new \DateTime());
            $position->setIpAddress($request->getClientIp() ?? '');

            // Cas 1 : Le JS a transmis les coordonnées GPS dans le formulaire
            $lat = $request->request->get('_geo_lat');
            $lng = $request->request->get('_geo_lng');

            if ($lat !== null && $lng !== null && is_numeric($lat) && is_numeric($lng)) {
                $position->setLatitude((float) $lat);
                $position->setLongitude((float) $lng);
                $position->setMethod('geolocation_api');
            } else {
                // Cas 2 : Fallback par IP
                $geoData = $this->geoIPService->getLocationFromIP(
                    $request->getClientIp() ?? '127.0.0.1'
                );
                $position->setLatitude($geoData['latitude']);
                $position->setLongitude($geoData['longitude']);
                $position->setMethod('ip_lookup');
            }

            $this->em->persist($position);
            $this->em->flush();

        } catch (\Throwable) {
            // La géolocalisation est non-critique — ne pas bloquer la connexion
        }
    }
}
