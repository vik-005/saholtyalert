<?php

namespace App\Service;

use App\Entity\Urgence72hPhase;
use App\Enum\PhaseUrgence;
use App\Message\SlaReminderMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Service de surveillance des SLA urgence 72h.
 * Exécuté toutes les 15 minutes par le Scheduler (SlaCheckTask).
 * Marque les phases en retard et envoie les rappels/escalades (Annexe E §3.4).
 */
class SlaMonitorService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
    ) {}

    public function checkAll(): array
    {
        $results = [
            'checked' => 0,
            'retards' => 0,
            'rappels' => 0,
            'escalades' => 0,
        ];

        $phases = $this->em->getRepository(Urgence72hPhase::class)
            ->createQueryBuilder('p')
            ->join('p.urgenceCase', 'c')
            ->where('c.statutCase = :active')
            ->andWhere('p.dateFin IS NULL')
            ->setParameter('active', 'active')
            ->getQuery()
            ->getResult();

        $now = new \DateTimeImmutable();

        foreach ($phases as $phase) {
            /** @var Urgence72hPhase $phase */
            $results['checked']++;
            $case = $phase->getUrgenceCase();

            // Vérification rappel (avant la limite SLA)
            $heureRappel = $case->getDateActivation()->modify(
                '+' . $phase->getPhase()->rapppelHeures() . ' hours'
            );

            if ($now >= $heureRappel && !$phase->isRappelEnvoye() && !$phase->isEnRetard()) {
                $phase->setRappelEnvoye(true);
                $this->bus->dispatch(new SlaReminderMessage(
                    caseId: $case->getId(),
                    phaseValue: $phase->getPhase()->value,
                    type: 'rappel',
                ));
                $results['rappels']++;
            }

            // Vérification retard SLA
            if ($now > $phase->getSlaHeureLimite() && !$phase->isEnRetard()) {
                $phase->setEnRetard(true);
                $this->bus->dispatch(new SlaReminderMessage(
                    caseId: $case->getId(),
                    phaseValue: $phase->getPhase()->value,
                    type: 'depassement',
                ));
                $results['retards']++;

                // Escalade spéciale si phase SUIVI (T+72h) → notification COMITE_AIT
                if ($phase->getPhase() === PhaseUrgence::SUIVI) {
                    $this->bus->dispatch(new SlaReminderMessage(
                        caseId: $case->getId(),
                        phaseValue: $phase->getPhase()->value,
                        type: 'escalade_comite_ait',
                    ));
                    $results['escalades']++;
                }
            }
        }

        $this->em->flush();

        return $results;
    }
}
