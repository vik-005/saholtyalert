<?php

namespace App\Tests\Unit;

use App\Entity\Alert;
use App\Entity\Market;
use App\Enum\AlertExploitabilite;
use App\Enum\AlertImpact;
use App\Enum\AlertStatut;
use App\Enum\AlertUrgence;
use App\Enum\FiabiliteSource;
use App\Message\AlertEscalationMessage;
use App\Repository\RuleConfigRepository;
use App\Service\EscalationRuleEngine;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Tests unitaires des 7 règles d'escalade (Partie B.4 du prompt expert).
 *
 * Chaque test construit une alerte remplissant EXACTEMENT la condition
 * de la règle et vérifie que l'action attendue se déclenche.
 * Ce sont des tests AUTOMATISÉS, pas une vérification visuelle.
 */
class EscalationRuleEngineTest extends TestCase
{
    private MessageBusInterface $bus;
    /** @var AlertEscalationMessage[] */
    private array $dispatchedMessages = [];

    protected function setUp(): void
    {
        $this->dispatchedMessages = [];

        // Bus capturant les messages dispatchés pour assertions
        $this->bus = $this->createMock(MessageBusInterface::class);
        $self      = $this;
        $this->bus
            ->method('dispatch')
            ->willReturnCallback(function (object $message) use ($self) {
                $self->dispatchedMessages[] = $message;
                return new Envelope($message);
            });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Règle 1 : Score ≥ 18 → Urgence 72h activée
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @test
     * Score ≥ 18 doit déclencher la création d'un Urgence72hCase et une notification.
     */
    public function testRegle1_ScoreCritique_DeclenchemantUrgence72h(): void
    {
        $alert = $this->buildScoredAlert(
            scoreGei: 25,
            urgence: AlertUrgence::IMMEDIAT,
            impact: AlertImpact::ELEVE,
            exploitabilite: AlertExploitabilite::ACTIONNABLE,
            fiabilite: FiabiliteSource::A,
            credibilite: 1
        );
        // Pas de case existant
        $this->assertNull($alert->getUrgence72hCase());

        $engine = $this->buildEngine();
        $engine->evaluate($alert);

        // Un Urgence72hCase doit être créé
        $this->assertNotNull($alert->getUrgence72hCase(), 'Règle 1 : Urgence72hCase doit être créé pour score ≥ 18');

        // Message dispatché
        $this->assertDispatchedType('urgence_72h_created');
    }

    /**
     * @test
     * Score < 18 ne doit PAS déclencher la règle 1.
     */
    public function testRegle1_ScoreInferieurA18_PasDeUrgence(): void
    {
        $alert = $this->buildScoredAlert(scoreGei: 17);

        $engine = $this->buildEngine();
        $engine->evaluate($alert);

        $this->assertNull($alert->getUrgence72hCase(), 'Règle 1 : Pas d\'Urgence72hCase pour score < 18');
        $this->assertNotDispatchedType('urgence_72h_created');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Règle 2 : Urgence Immédiat + Impact Élevé → transmission_prioritaire
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @test
     * Urgence = Immédiat ET Impact = Élevé → flag transmissionPrioritaire = true.
     */
    public function testRegle2_UrgenceImmediatImpactEleve_TransmissionPrioritaire(): void
    {
        $alert = $this->buildScoredAlert(
            scoreGei: 12,
            urgence: AlertUrgence::IMMEDIAT,
            impact: AlertImpact::ELEVE
        );

        $engine = $this->buildEngine();
        $engine->evaluate($alert);

        $this->assertTrue($alert->isTransmissionPrioritaire(), 'Règle 2 : transmissionPrioritaire doit être true');
        $this->assertDispatchedType('transmission_prioritaire');
    }

    /**
     * @test
     * Urgence Immédiat SANS Impact Élevé → pas de transmission prioritaire.
     */
    public function testRegle2_UrgenceImmediatImpactMoyen_PasPrioritaire(): void
    {
        $alert = $this->buildScoredAlert(
            scoreGei: 10,
            urgence: AlertUrgence::IMMEDIAT,
            impact: AlertImpact::MOYEN
        );

        $engine = $this->buildEngine();
        $engine->evaluate($alert);

        $this->assertFalse($alert->isTransmissionPrioritaire(), 'Règle 2 : Impact Moyen ne doit pas déclencher transmission_prioritaire');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Règle 3 : Exploitabilité Actionnable + Crédibilité ≤ 2 → tache_analyse_rapide
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @test
     * Exploitabilité Actionnable + Crédibilité = 1 (≥ 2 en valeur score, car inversé : score=4)
     * → message tache_analyse_rapide dispatché.
     */
    public function testRegle3_ActionnableCredibilite1_TacheAnalyseRapide(): void
    {
        $alert = $this->buildScoredAlert(
            scoreGei: 15,
            exploitabilite: AlertExploitabilite::ACTIONNABLE,
            credibilite: 1  // raw=1 → score=4 (≥ 3, condition : raw ≤ 2)
        );

        $engine = $this->buildEngine();
        $engine->evaluate($alert);

        $this->assertDispatchedType('tache_analyse_rapide', 'Règle 3 : Actionnable + Crédibilité 1 doit déclencher tache_analyse_rapide');
    }

    /**
     * @test
     * Exploitabilité Actionnable + Crédibilité = 3 (score inversé 2 — en dessous du seuil)
     * → PAS de tache_analyse_rapide.
     */
    public function testRegle3_ActionnableCredibilite3_PasDeTache(): void
    {
        $alert = $this->buildScoredAlert(
            scoreGei: 8,
            exploitabilite: AlertExploitabilite::ACTIONNABLE,
            credibilite: 3  // raw=3 → pas ≤ 2 → condition non remplie
        );

        $engine = $this->buildEngine();
        $engine->evaluate($alert);

        $this->assertNotDispatchedType('tache_analyse_rapide');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Règle 5 : Score 14-17 → validation_saholty_requise
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @test
     * Score = 14 → blocage en attente validation SAHOLTY.
     */
    public function testRegle5_Score14_ValidationSaholty(): void
    {
        $alert = $this->buildScoredAlert(scoreGei: 14);

        $engine = $this->buildEngine();
        $engine->evaluate($alert);

        $this->assertDispatchedType('validation_saholty_requise', 'Règle 5 : Score 14 doit déclencher validation_saholty_requise');
    }

    /**
     * @test
     * Score = 17 → toujours dans la plage 14–17, validation requise.
     */
    public function testRegle5_Score17_ValidationSaholty(): void
    {
        $alert = $this->buildScoredAlert(scoreGei: 17);

        $engine = $this->buildEngine();
        $engine->evaluate($alert);

        $this->assertDispatchedType('validation_saholty_requise');
    }

    /**
     * @test
     * Score = 18 → CRITIQUE, pas de validation SAHOLTY (règle 1 à la place).
     */
    public function testRegle5_Score18_PasDeValidationSaholty(): void
    {
        $alert = $this->buildScoredAlert(
            scoreGei: 18,
            fiabilite: FiabiliteSource::A,
            credibilite: 1,
            urgence: AlertUrgence::IMMEDIAT,
            impact: AlertImpact::ELEVE,
            exploitabilite: AlertExploitabilite::ACTIONNABLE
        );

        $engine = $this->buildEngine();
        $engine->evaluate($alert);

        $this->assertNotDispatchedType('validation_saholty_requise', 'Règle 5 ne doit pas se déclencher pour score ≥ 18');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Règle 6 : Fiabilité C/D + Impact Élevé → demande_corroboration
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @test
     * Fiabilité C + Impact Élevé → demande_corroboration.
     */
    public function testRegle6_FiabiliteC_ImpactEleve_DemandeCorroboration(): void
    {
        $alert = $this->buildScoredAlert(
            scoreGei: 10,
            fiabilite: FiabiliteSource::C,
            impact: AlertImpact::ELEVE
        );

        $engine = $this->buildEngine();
        $engine->evaluate($alert);

        $this->assertDispatchedType('demande_corroboration', 'Règle 6 : Fiabilité C + Impact Élevé doit déclencher demande_corroboration');
    }

    /**
     * @test
     * Fiabilité D + Impact Élevé → demande_corroboration.
     */
    public function testRegle6_FiabiliteD_ImpactEleve_DemandeCorroboration(): void
    {
        $alert = $this->buildScoredAlert(
            scoreGei: 8,
            fiabilite: FiabiliteSource::D,
            impact: AlertImpact::ELEVE
        );

        $engine = $this->buildEngine();
        $engine->evaluate($alert);

        $this->assertDispatchedType('demande_corroboration');
    }

    /**
     * @test
     * Fiabilité A + Impact Élevé → PAS de corroboration (source fiable).
     */
    public function testRegle6_FiabiliteA_ImpactEleve_PasDeCorroboration(): void
    {
        $alert = $this->buildScoredAlert(
            scoreGei: 20,
            fiabilite: FiabiliteSource::A,
            impact: AlertImpact::ELEVE,
            credibilite: 1,
            urgence: AlertUrgence::IMMEDIAT,
            exploitabilite: AlertExploitabilite::ACTIONNABLE
        );

        $engine = $this->buildEngine();
        $engine->evaluate($alert);

        $this->assertNotDispatchedType('demande_corroboration');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Règle 7 : Crédibilité 3 + Urgence élevée → surveillance_renforcee
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @test
     * Crédibilité = 3 + Urgence = Immédiat → surveillanceRenforcee = true.
     */
    public function testRegle7_Credibilite3_UrgenceImmediatemediat_SurveillanceRenforcee(): void
    {
        $alert = $this->buildScoredAlert(
            scoreGei: 10,
            credibilite: 3,
            urgence: AlertUrgence::IMMEDIAT
        );

        $engine = $this->buildEngine();
        $engine->evaluate($alert);

        $this->assertTrue($alert->isSurveillanceRenforcee(), 'Règle 7 : Crédibilité 3 + Urgence Immédiat → surveillanceRenforcee=true');
    }

    /**
     * @test
     * Crédibilité = 3 + Urgence = 72h → surveillanceRenforcee = true.
     */
    public function testRegle7_Credibilite3_Urgence72h_SurveillanceRenforcee(): void
    {
        $alert = $this->buildScoredAlert(
            scoreGei: 9,
            credibilite: 3,
            urgence: AlertUrgence::SOIXANTE_DOUZE_H
        );

        $engine = $this->buildEngine();
        $engine->evaluate($alert);

        $this->assertTrue($alert->isSurveillanceRenforcee());
    }

    /**
     * @test
     * Crédibilité = 3 + Urgence = Routine → PAS de surveillance renforcée.
     */
    public function testRegle7_Credibilite3_UrgenceRoutine_PasDeSurveillance(): void
    {
        $alert = $this->buildScoredAlert(
            scoreGei: 5,
            credibilite: 3,
            urgence: AlertUrgence::ROUTINE
        );

        $engine = $this->buildEngine();
        $engine->evaluate($alert);

        $this->assertFalse($alert->isSurveillanceRenforcee(), 'Règle 7 : Urgence Routine ne déclenche pas la surveillance renforcée');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function buildEngine(): EscalationRuleEngine
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturn(null);

        $ruleConfigRepo = $this->createMock(RuleConfigRepository::class);
        $ruleConfigRepo->method('findAllAsMap')->willReturn([
            'score_critique'   => '18',
            'score_eleve_min'  => '14',
            'score_modere_min' => '10',
        ]);

        return new EscalationRuleEngine($em, $this->bus, $ruleConfigRepo);
    }

    private function buildScoredAlert(
        int $scoreGei = 10,
        ?AlertUrgence $urgence = null,
        ?AlertImpact $impact = null,
        ?AlertExploitabilite $exploitabilite = null,
        ?FiabiliteSource $fiabilite = null,
        int $credibilite = 2,
    ): Alert {
        $market = new Market();
        $market->setCodeIso3('BEN');
        $market->setNom('Bénin');

        $alert = new Alert();
        $alert->setMarket($market);
        $alert->setResumeExecutif('Test règle escalade');
        $alert->setStatut(AlertStatut::EN_COURS);
        $alert->setScoreGei($scoreGei);

        if ($urgence)        $alert->setUrgence($urgence);
        if ($impact)         $alert->setImpact($impact);
        if ($exploitabilite) $alert->setExploitabilite($exploitabilite);
        if ($fiabilite)      $alert->setFiabiliteSource($fiabilite);

        $alert->setCredibiliteContenu($credibilite);

        // Simuler un ID pour le message
        $reflector = new \ReflectionProperty(Alert::class, 'id');
        $reflector->setValue($alert, 1);

        return $alert;
    }

    private function assertDispatchedType(string $type, string $message = ''): void
    {
        $types = array_map(
            fn(object $m) => $m instanceof AlertEscalationMessage ? $m->getType() : '',
            $this->dispatchedMessages
        );
        $this->assertContains(
            $type,
            $types,
            $message ?: sprintf('Message de type "%s" doit avoir été dispatché', $type)
        );
    }

    private function assertNotDispatchedType(string $type, string $message = ''): void
    {
        $types = array_map(
            fn(object $m) => $m instanceof AlertEscalationMessage ? $m->getType() : '',
            $this->dispatchedMessages
        );
        $this->assertNotContains(
            $type,
            $types,
            $message ?: sprintf('Message de type "%s" ne doit PAS avoir été dispatché', $type)
        );
    }
}
