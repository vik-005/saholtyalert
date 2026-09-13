<?php

namespace App\EventSubscriber;

use App\Dto\AuditFilterDTO;
use App\Entity\Alert;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Cache invalidation pour le module Audit.
 *
 * Après un flush Doctrine (création/modification/suppression d'alerte),
 * invalide le cache des agrégations audit (300s TTL).
 *
 * Utilise le tag 'audit_stats' pour invalider tous les entries d'un seul coup.
 * La vérification method_exists() est un garde-fou : si le pool cache n'est
 * pas tag-aware, on ne fait rien (les entrées expireront naturellement via TTL).
 */
#[AsDoctrineListener(event: Events::postFlush)]
class AuditCacheInvalidationSubscriber
{
    public function __construct(
        private readonly CacheInterface $cache,
    ) {}

    public function postFlush(PostFlushEventArgs $args): void
    {
        $em   = $args->getObjectManager();
        $uow  = $em->getUnitOfWork();
        $dirty = false;

        // Vérifier uniquement les Alert modifiées
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof Alert) {
                $dirty = true;
                break;
            }
        }
        if (!$dirty) {
            foreach ($uow->getScheduledEntityUpdates() as $entity) {
                if ($entity instanceof Alert) {
                    $dirty = true;
                    break;
                }
            }
        }
        if (!$dirty) {
            foreach ($uow->getScheduledEntityDeletions() as $entity) {
                if ($entity instanceof Alert) {
                    $dirty = true;
                    break;
                }
            }
        }

        if (!$dirty) {
            return;
        }

        // Invalider par tags si le pool le supporte
        try {
            if (method_exists($this->cache, 'invalidateTags')) {
                $this->cache->invalidateTags(['audit_stats']);
            }
        } catch (\Throwable) {
            // Non critique — le TTL fera son travail
        }
    }
}
