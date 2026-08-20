<?php

namespace App\EventSubscriber;

use App\Entity\Alert;
use App\Service\AlertCodeGeneratorService;
use App\Service\EscalationRuleEngine;
use App\Service\ScoreCalculatorService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;

/**
 * Subscriber Doctrine pour le recalcul automatique du score GEI.
 * Déclenché sur chaque création/modification d'une alerte (onFlush).
 * Génère également le code GEI au premier persist.
 */
#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::onFlush)]
class AlertScoreSubscriber
{
    public function __construct(
        private readonly ScoreCalculatorService $scoreCalculator,
        private readonly EscalationRuleEngine $escalationEngine,
        private readonly AlertCodeGeneratorService $codeGenerator,
    ) {}

    /** Génère le code GEI au premier persist si pas encore défini */
    public function prePersist(PrePersistEventArgs $args): void
    {
        $object = $args->getObject();
        if (!$object instanceof Alert) {
            return;
        }

        if (null === $object->getCodeGei() && null !== $object->getMarket()) {
            $code = $this->codeGenerator->generate($object);
            $object->setCodeGei($code);
        }

        // Calcul initial du score
        if ($object->isScoreable()) {
            $this->scoreCalculator->calculate($object);

            $em = $args->getObjectManager();
            $entry = $this->scoreCalculator->createHistoryEntry($object);
            $em->persist($entry);
        }
    }

    /** Recalcul du score sur chaque flush contenant une alerte modifiée */
    public function onFlush(OnFlushEventArgs $args): void
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

            $changed = $this->scoreCalculator->calculate($entity);

            if ($changed) {
                $classMetadata = $em->getClassMetadata(Alert::class);
                $uow->recomputeSingleEntityChangeSet($classMetadata, $entity);

                $entry = $this->scoreCalculator->createHistoryEntry($entity);
                $em->persist($entry);
                $entryMeta = $em->getClassMetadata(get_class($entry));
                $uow->computeChangeSet($entryMeta, $entry);

                $this->escalationEngine->evaluate($entity);
            }

            // Notification Agent si le statut a changé
            $changeSet = $uow->getEntityChangeSet($entity);
            if (isset($changeSet['statut']) && $changeSet['statut'][0] !== $changeSet['statut'][1]) {
                $this->notifyAgentStatutChange($entity, $changeSet['statut'][1], $em);
            }
        }
    }

    /**
     * Crée une notification en base pour l'Agent émetteur de l'alerte
     * quand le statut change (validation, rejet, demande de complément…).
     */
    private function notifyAgentStatutChange(Alert $alert, mixed $newStatut, $em): void
    {
        $agent = $alert->getEmetteur();
        if (null === $agent) {
            return;
        }

        $statutLabel = $newStatut instanceof \App\Enum\AlertStatut
            ? $newStatut->label()
            : (string)$newStatut;

        $notif = new \App\Entity\Notification();
        $notif->setDestinataire($agent);
        $notif->setAlert($alert);
        $notif->setType('statut_change');
        $notif->setContenu(sprintf(
            'Votre alerte %s a changé de statut : %s.',
            $alert->getCodeGei() ?? '#' . $alert->getId(),
            $statutLabel
        ));

        $em->persist($notif);
        $uow = $em->getUnitOfWork();
        $uow->computeChangeSet($em->getClassMetadata(\App\Entity\Notification::class), $notif);
    }
}
