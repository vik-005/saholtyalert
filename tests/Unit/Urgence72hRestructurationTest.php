<?php

namespace App\Tests\Unit;

use App\Entity\Alert;
use App\Entity\Urgence72hCase;
use App\Entity\Urgence72hPhase;
use App\Enum\AlertImpact;
use App\Enum\AlertStatut;
use App\Enum\AlertUrgence;
use App\Enum\PhaseUrgence;
use App\Service\EscalationRuleEngine;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Tests de validation pour la restructuration de l'Urgence 72h (Spec Partie F).
 * - 3 phases exactes : Détection (T0), Coordination (T+48h), Suivi & clôture (T+72h)
 * - Phase Détection terminée immédiatement à T0 dès création
 * - Progression manuelle pilotée par le Manager et gestion du retard SLA
 */
class Urgence72hRestructurationTest extends TestCase
{
    private $em;
    private $bus;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->bus = $this->createMock(MessageBusInterface::class);
        $this->bus->method('dispatch')->willReturnCallback(fn($msg) => new Envelope($msg));
    }

    /**
     * Test F.1 : Vérification que l'enum PhaseUrgence ne contient que 3 phases exactes
     * et qu'aucune trace de qualification/validation ne subsiste.
     */
    public function testTroisPhasesUrgenceExactes(): void
    {
        $phases = PhaseUrgence::cases();
        $this->assertCount(3, $phases, 'Le protocole Urgence 72h doit comporter exactement 3 phases.');

        $values = array_map(fn(PhaseUrgence $p) => $p->value, $phases);
        $this->assertEquals(['detection', 'coordination', 'suivi'], $values);

        // Vérification des SLA
        $this->assertEquals(0, PhaseUrgence::DETECTION->slaHeures());
        $this->assertEquals(48, PhaseUrgence::COORDINATION->slaHeures());
        $this->assertEquals(72, PhaseUrgence::SUIVI->slaHeures());
    }

    /**
     * Test F.1 & F.2 : À la création d'un cas d'urgence 72h, la phase Détection
     * est automatiquement marquée terminée (T0) et Coordination est active.
     */
    public function testCreationCasUrgence_DetectionAutomatiquementCompletee(): void
    {
        $alert = new Alert();
        $alert->setResumeExecutif('Menace corridor critique');
        $alert->setScoreGei(20);
        $alert->setStatut(AlertStatut::VALIDEE);
        $alert->setUrgence(AlertUrgence::SOIXANTE_DOUZE_H);
        $alert->setImpact(AlertImpact::ELEVE);

        $ruleConfigRepo = $this->createMock(\App\Repository\RuleConfigRepository::class);
        $ruleConfigRepo->method('findAllAsMap')->willReturn([
            'score_critique'   => '18',
            'score_eleve_min'  => '14',
            'score_modere_min' => '10',
        ]);

        $engine = new EscalationRuleEngine($this->em, $this->bus, $ruleConfigRepo);
        $engine->evaluate($alert);

        $case = $alert->getUrgence72hCase();
        $this->assertNotNull($case, 'Un cas d\'urgence 72h doit être initialisé');

        $phases = $case->getSortedPhases();
        $this->assertCount(3, $phases, 'Le cas doit comporter exactement 3 phases instanciées');

        // Phase 0 : Détection
        $p0 = $phases[0];
        $this->assertEquals(PhaseUrgence::DETECTION, $p0->getPhase());
        $this->assertTrue($p0->isTerminee(), 'La phase Détection doit être marquée terminée dès la création');
        $this->assertNotNull($p0->getDateFin());

        // Phase 1 : Coordination
        $p1 = $phases[1];
        $this->assertEquals(PhaseUrgence::COORDINATION, $p1->getPhase());
        $this->assertNotNull($p1->getDateDebut(), 'Coordination démarre à T0');
        $this->assertFalse($p1->isTerminee(), 'Coordination n\'est pas encore terminée');

        // Phase 2 : Suivi & clôture
        $p2 = $phases[2];
        $this->assertEquals(PhaseUrgence::SUIVI, $p2->getPhase());
        $this->assertFalse($p2->isTerminee());
    }

    /**
     * Test F.2 : Action manuelle et détection simultanée du retard SLA
     * (isTermineeAvecRetard() doit valoir true si la phase a dépassé son SLA).
     */
    public function testProgressionManuelleEtRetardSimultane(): void
    {
        $phase = new Urgence72hPhase();
        $phase->setPhase(PhaseUrgence::COORDINATION);

        $dateActivation = new \DateTimeImmutable('2026-09-01 08:00:00');
        $phase->setDateDebut($dateActivation);
        $phase->setSlaHeureLimite($dateActivation->modify('+48 hours')); // 2026-09-03 08:00:00

        // 1. Phase en cours, dans les temps
        $this->assertFalse($phase->isTerminee());
        $this->assertFalse($phase->isTermineeAvecRetard());

        // 2. Phase en cours, marquée en retard par le SlaMonitor
        $phase->setEnRetard(true);
        $this->assertTrue($phase->isEnRetard());
        $this->assertFalse($phase->isTerminee(), 'Toujours pas terminée');
        $this->assertFalse($phase->isTermineeAvecRetard());

        // 3. Le Manager valide manuellement la phase à T+50h
        $dateFin = $dateActivation->modify('+50 hours');
        $phase->setDateFin($dateFin);

        $this->assertTrue($phase->isTerminee());
        $this->assertTrue($phase->isTermineeAvecRetard(), 'La phase doit être reconnue comme complétée AVEC retard');
    }
}
