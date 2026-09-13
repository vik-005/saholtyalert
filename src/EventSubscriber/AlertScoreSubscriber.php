<?php

namespace App\EventSubscriber;

use App\Entity\Alert;
use App\Service\AlertCodeGeneratorService;
use App\Service\EscalationRuleEngine;
use App\Service\ScoreCalculatorService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Events;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;

/**
 * Subscriber Doctrine — recalcul automatique du score GEI.
 *
 * GARDE-FOU ANTI-BOUCLE :
 *   onFlush peut être rappelé récursivement si EscalationRuleEngine ou
 *   notifyAgentStatutChange persist/flush de nouvelles entités.
 *   Le flag $inFlush bloque la ré-entrance.
 */
#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::onFlush)]
class AlertScoreSubscriber
{
    /** Protège contre les appels récursifs depuis onFlush */
    private bool $inFlush = false;

    public function __construct(
        private readonly ScoreCalculatorService $scoreCalculator,
        private readonly EscalationRuleEngine $escalationEngine,
        private readonly AlertCodeGeneratorService $codeGenerator,
    ) {}

    // ── prePersist — code GEI + score initial ────────────────────────────────

    public function prePersist(PrePersistEventArgs $args): void
    {
        $object = $args->getObject();
        if (!$object instanceof Alert) {
            return;
        }

        // Générer le code GEI si absent
        if (null === $object->getCodeGei() && null !== $object->getMarket()) {
            $object->setCodeGei($this->codeGenerator->generate($object));
        }

        // Calcul initial du score (sans flush — prePersist est dans le flush en cours)
        if ($object->isScoreable()) {
            $this->scoreCalculator->calculate($object);

            $em    = $args->getObjectManager();
            $entry = $this->scoreCalculator->createHistoryEntry($object);
            $em->persist($entry);
            // Pas de flush ici — Doctrine le fera automatiquement à la fin du flush parent
        }
    }

    // ── onFlush — recalcul sur modification ─────────────────────────────────

    public function onFlush(OnFlushEventArgs $args): void
    {
        // Garde-fou : empêcher les appels récursifs
        if ($this->inFlush) {
            return;
        }

        $this->inFlush = true;

        try {
            $this->doOnFlush($args);
        } finally {
            // Toujours libérer le verrou, même en cas d'exception
            $this->inFlush = false;
        }
    }

    private function doOnFlush(OnFlushEventArgs $args): void
    {
        $em  = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        $entities = array_merge(
            array_values($uow->getScheduledEntityUpdates()),
            array_values($uow->getScheduledEntityInsertions()),
        );

        foreach ($entities as $entity) {
            if (!$entity instanceof Alert) {
                continue;
            }

            if (!$entity->isScoreable()) {
                continue;
            }

            $oldScore = $entity->getScoreGei();
            $changed = $this->scoreCalculator->calculate($entity);

            if ($changed) {
                $classMetadata = $em->getClassMetadata(Alert::class);
                $uow->recomputeSingleEntityChangeSet($classMetadata, $entity);

                $entry     = $this->scoreCalculator->createHistoryEntry($entity, 'system', $oldScore);
                $em->persist($entry);
                $entryMeta = $em->getClassMetadata(get_class($entry));
                $uow->computeChangeSet($entryMeta, $entry);
            }

            $this->escalationEngine->evaluate($entity);

            // Notification Agent si le statut a changé
            $changeSet = $uow->getEntityChangeSet($entity);
            if (isset($changeSet['statut']) && $changeSet['statut'][0] !== $changeSet['statut'][1]) {
                $this->notifyAgentStatutChange($entity, $changeSet['statut'][1], $em, $uow);
            }
        }
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function notifyAgentStatutChange(
        Alert  $alert,
        mixed  $newStatut,
        object $em,
        object $uow,
    ): void {
        $agent = $alert->getEmetteur();
        if (null === $agent) {
            return;
        }

        $statutLabel = $newStatut instanceof \App\Enum\AlertStatut
            ? $newStatut->label()
            : (is_string($newStatut) ? $newStatut : (string) $newStatut);

        $notif = new \App\Entity\Notification();
        $notif->setDestinataire($agent);
        $notif->setAlert($alert);
        $notif->setType('statut_change');
        $notif->setContenu(sprintf(
            'Votre alerte %s a changé de statut : %s.',
            $alert->getCodeGei() ?? '#' . $alert->getId(),
            $statutLabel,
        ));

        $em->persist($notif);
        // Enregistrer dans l'UoW courant sans déclencher un nouveau flush
        $uow->computeChangeSet(
            $em->getClassMetadata(\App\Entity\Notification::class),
            $notif,
        );
    }
}
