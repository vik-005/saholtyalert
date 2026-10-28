<?php

namespace App\Tests\Unit;

use App\Entity\Alert;
use App\Entity\Urgence72hCase;
use App\Entity\Urgence72hPhase;
use App\Enum\AlertStatut;
use App\Enum\FiabiliteSource;
use App\Enum\PhaseUrgence;
use App\EventSubscriber\AlertScoreSubscriber;
use App\Service\AlertCodeGeneratorService;
use App\Service\EscalationRuleEngine;
use App\Service\ScoreCalculatorService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\TestCase;

class AlertScoreSubscriberTest extends TestCase
{
    public function testNewUrgenceCaseAndAllPhasesAreScheduledDuringFlush(): void
    {
        $alert = new Alert();
        $alert->setStatut(AlertStatut::VALIDEE);
        $alert->setFiabiliteSource(FiabiliteSource::A);
        $alert->setCredibiliteContenu(1);
        $alert->setUrgence(\App\Enum\AlertUrgence::ROUTINE);
        $alert->setImpact(\App\Enum\AlertImpact::ELEVE);
        $alert->setExploitabilite(\App\Enum\AlertExploitabilite::ACTIONNABLE);

        $case = new Urgence72hCase();
        $case->setAlert($alert);
        foreach (PhaseUrgence::cases() as $phaseType) {
            $phase = new Urgence72hPhase();
            $phase->setPhase($phaseType);
            $phase->setSlaHeureLimite($case->getDateActivation()->modify('+' . $phaseType->slaHeures() . ' hours'));
            $case->addPhase($phase);
        }

        $scoreCalculator = $this->createMock(ScoreCalculatorService::class);
        $scoreCalculator->method('calculate')->willReturn(false);

        $escalationEngine = $this->createMock(EscalationRuleEngine::class);
        $escalationEngine->expects(self::once())
            ->method('evaluate')
            ->with($alert)
            ->willReturnCallback(static function (Alert $evaluatedAlert) use ($case): void {
                $evaluatedAlert->setUrgence72hCase($case);
            });

        $metadata = $this->createMock(ClassMetadata::class);
        $metadataByClass = [
            Urgence72hCase::class => $metadata,
            Urgence72hPhase::class => $metadata,
        ];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $unitOfWork = $this->createMock(UnitOfWork::class);
        $entityManager->method('getUnitOfWork')->willReturn($unitOfWork);
        $entityManager->method('getClassMetadata')
            ->willReturnCallback(static fn (string $class) => $metadataByClass[$class]);
        $unitOfWork->method('getScheduledEntityUpdates')->willReturn([$alert]);
        $unitOfWork->method('getScheduledEntityInsertions')->willReturn([]);
        $unitOfWork->method('getEntityChangeSet')->with($alert)->willReturn([]);

        $scheduledEntities = [];
        $unitOfWork->expects(self::exactly(4))
            ->method('computeChangeSet')
            ->willReturnCallback(static function (ClassMetadata $classMetadata, object $entity) use (&$scheduledEntities): void {
                $scheduledEntities[] = $entity::class;
            });

        $subscriber = new AlertScoreSubscriber(
            $scoreCalculator,
            $escalationEngine,
            $this->createMock(AlertCodeGeneratorService::class),
        );
        $subscriber->onFlush(new OnFlushEventArgs($entityManager));

        self::assertSame([
            Urgence72hCase::class,
            Urgence72hPhase::class,
            Urgence72hPhase::class,
            Urgence72hPhase::class,
        ], $scheduledEntities);
    }
}
