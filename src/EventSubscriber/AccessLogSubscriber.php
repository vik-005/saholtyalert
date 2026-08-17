<?php

namespace App\EventSubscriber;

use App\Entity\AccessLog;
use App\Entity\Alert;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Events;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Subscriber pour la traçabilité obligatoire (Annexe D §6/§8).
 * Journalise les lectures d'alertes sensibles (confidentielle/restreinte).
 */
#[AsDoctrineListener(event: Events::postLoad)]
class AccessLogSubscriber
{
    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
    ) {}

    public function postLoad(PostLoadEventArgs $args): void
    {
        $entity = $args->getObject();

        if (!$entity instanceof Alert) {
            return;
        }

        // Log uniquement les alertes confidentielles ou restreintes
        if (!in_array($entity->getSensibilite()->value, ['confidentielle', 'restreinte'])) {
            return;
        }

        $user = $this->security->getUser();
        if (null === $user) {
            return;
        }

        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            return;
        }

        $log = new AccessLog();
        $log->setUser($user instanceof \App\Entity\User ? $user : null);
        $log->setAlert($entity);
        $log->setAction(AccessLog::ACTION_LECTURE);
        $log->setIpAdresse($request->getClientIp());
        $log->setUserAgent($request->headers->get('User-Agent'));
        $log->setResultat('success');

        // Utiliser un EM séparé ou différé pour éviter les boucles
        // En production : dispatcher un message async
        try {
            $this->em->persist($log);
            // Pas de flush ici — le flush sera fait par le flux normal
        } catch (\Exception) {
            // Silently fail pour ne pas bloquer la lecture
        }
    }
}
