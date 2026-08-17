<?php

namespace App\Tests\Unit;

use App\Entity\Alert;
use App\Enum\AlertExploitabilite;
use App\Enum\AlertImpact;
use App\Enum\AlertUrgence;
use App\Enum\FiabiliteSource;
use App\Enum\NiveauPriorite;
use App\Repository\RuleConfigRepository;
use App\Service\ScoreCalculatorService;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires du moteur de scoring GEI (Partie B du prompt expert).
 *
 * Couvre :
 *  B.1 — Table de conversion exacte (Fiabilité lettres A-D, Crédibilité inversée)
 *  B.2 — Formule : Score = (Fiabilité × Crédibilité) + Urgence + Impact + Exploitabilité
 *  B.3 — Seuils de priorité depuis rule_config (avec fallback défauts Annexe C)
 */
class ScoreCalculatorTest extends TestCase
{
    private ScoreCalculatorService $calculator;

    protected function setUp(): void
    {
        // Bouchon du repository — renvoie les seuils défauts Annexe C
        $ruleConfigRepo = $this->createMock(RuleConfigRepository::class);
        $ruleConfigRepo->method('getInt')
            ->willReturnMap([
                ['score_critique',   18, 18],
                ['score_eleve_min',  14, 14],
                ['score_modere_min', 10, 10],
            ]);

        $this->calculator = new ScoreCalculatorService($ruleConfigRepo);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // B.2 — Test obligatoire du maximum (Score 25 = CRITIQUE)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @test
     * Fiabilité A (score 4) × Crédibilité 1 (score inversé 4) = 16
     * + Urgence Immédiat (3) + Impact Élevé (3) + Exploitabilité Actionnable (3) = 25
     * → Doit être classé CRITIQUE (≥ 18)
     */
    public function testScoreMaximumCritique(): void
    {
        $alert = $this->buildAlert(
            FiabiliteSource::A,
            credibilite: 1,
            AlertUrgence::IMMEDIAT,
            AlertImpact::ELEVE,
            AlertExploitabilite::ACTIONNABLE
        );

        $score = $this->calculator->computeScore($alert);
        $this->assertEquals(25, $score, 'Score maximum (Fiabilité A × Crédibilité 1 + Immédiat + Élevé + Actionnable) doit être 25');

        $this->calculator->calculate($alert);
        $this->assertEquals(25, $alert->getScoreGei());
        $this->assertEquals(NiveauPriorite::CRITIQUE, $alert->getNiveauPriorite());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // B.2 — Test obligatoire du plancher (Score 4 = FAIBLE)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @test
     * Fiabilité D (score 1) × Crédibilité 4 (score inversé 1) = 1
     * + Urgence Routine (1) + Impact Faible (1) + Exploitabilité Archivage (1) = 4
     * → Doit être classé FAIBLE (≤ 9)
     */
    public function testScorePlancher_D_Cred4_Routine_Faible_Archivage(): void
    {
        $alert = $this->buildAlert(
            FiabiliteSource::D,
            credibilite: 4,
            AlertUrgence::ROUTINE,
            AlertImpact::FAIBLE,
            AlertExploitabilite::ARCHIVAGE
        );

        $score = $this->calculator->computeScore($alert);
        $this->assertEquals(4, $score, 'Score plancher (Fiabilité D × Crédibilité 4 + Routine + Faible + Archivage) doit être 4');

        $this->calculator->calculate($alert);
        $this->assertEquals(4, $alert->getScoreGei());
        $this->assertEquals(NiveauPriorite::FAIBLE, $alert->getNiveauPriorite());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // B.1 — Vérification de la crédibilité INVERSÉE (piège d'implémentation)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @test
     * Crédibilité = 1 (confirmée, PLUS crédible) doit contribuer score 4 dans la formule.
     * Pas 1 point — c'est l'erreur classique.
     */
    public function testCredibilite1EquivautScore4(): void
    {
        $alert = $this->buildAlert(
            FiabiliteSource::B,  // score 3
            credibilite: 1,       // doit valoir 4 (inversé)
            AlertUrgence::ROUTINE,
            AlertImpact::FAIBLE,
            AlertExploitabilite::ARCHIVAGE
        );

        // (3 × 4) + 1 + 1 + 1 = 15 (Élevé, pas Modéré)
        $score = $this->calculator->computeScore($alert);
        $this->assertEquals(15, $score, 'Crédibilité 1 doit contribuer 4 points (inversée) — pas 1');
        $this->assertEquals(NiveauPriorite::ELEVE, $this->calculator->resolveNiveauPriorite($score));
    }

    /**
     * @test
     * Crédibilité = 4 (non vérifiable, MOINS crédible) doit contribuer score 1.
     */
    public function testCredibilite4EquivautScore1(): void
    {
        $alert = $this->buildAlert(
            FiabiliteSource::A,  // score 4
            credibilite: 4,       // doit valoir 1 (inversé)
            AlertUrgence::ROUTINE,
            AlertImpact::FAIBLE,
            AlertExploitabilite::ARCHIVAGE
        );

        // (4 × 1) + 1 + 1 + 1 = 7 (Faible)
        $score = $this->calculator->computeScore($alert);
        $this->assertEquals(7, $score, 'Crédibilité 4 doit contribuer 1 point seulement (inversée)');
        $this->assertEquals(NiveauPriorite::FAIBLE, $this->calculator->resolveNiveauPriorite($score));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // B.1 — Vérification enum Fiabilité lettres A/B/C/D (pas numérique)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @test
     * La Fiabilité doit utiliser des lettres A/B/C/D, pas 1/2/3/4.
     * Vérification que l'enum a bien une valeur backing string = lettre.
     */
    public function testFiabiliteEnumValeurEstLettre(): void
    {
        $this->assertEquals('A', FiabiliteSource::A->value);
        $this->assertEquals('B', FiabiliteSource::B->value);
        $this->assertEquals('C', FiabiliteSource::C->value);
        $this->assertEquals('D', FiabiliteSource::D->value);
    }

    /**
     * @test
     * Vérification de la table de conversion exacte des scores Fiabilité.
     */
    public function testFiabiliteScoresExacts(): void
    {
        $this->assertEquals(4, FiabiliteSource::A->score(), 'A=4');
        $this->assertEquals(3, FiabiliteSource::B->score(), 'B=3');
        $this->assertEquals(2, FiabiliteSource::C->score(), 'C=2');
        $this->assertEquals(1, FiabiliteSource::D->score(), 'D=1');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // B.3 — Seuils de priorité (lecture depuis rule_config)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @test
     * Score = 18 → frontière exacte CRITIQUE (≥ 18)
     */
    public function testSeuilFrontiereCritique(): void
    {
        // Fiabilité A (4) × Crédibilité 1 (4) = 16, + Routine (1) + Faible (1) + À compléter (2) = 20... ajuster
        // Fiabilité B (3) × Crédibilité 2 (3) = 9, + Immédiat (3) + Élevé (3) + Actionnable (3) = 18 ✓
        $alert = $this->buildAlert(
            FiabiliteSource::B,
            credibilite: 2,
            AlertUrgence::IMMEDIAT,
            AlertImpact::ELEVE,
            AlertExploitabilite::ACTIONNABLE
        );

        $score = $this->calculator->computeScore($alert);
        $this->assertEquals(18, $score);
        $this->assertEquals(NiveauPriorite::CRITIQUE, $this->calculator->resolveNiveauPriorite($score));
    }

    /**
     * @test
     * Score = 17 → ÉLEVÉ (14 ≤ score ≤ 17)
     */
    public function testSeuilFrontiereEleve(): void
    {
        // Fiabilité C (2) × Crédibilité 1 (4) = 8, + Immédiat (3) + Élevé (3) + Actionnable (3) = 17 ✓
        $alert = $this->buildAlert(
            FiabiliteSource::C,
            credibilite: 1,
            AlertUrgence::IMMEDIAT,
            AlertImpact::ELEVE,
            AlertExploitabilite::ACTIONNABLE
        );

        $score = $this->calculator->computeScore($alert);
        $this->assertEquals(17, $score);
        $this->assertEquals(NiveauPriorite::ELEVE, $this->calculator->resolveNiveauPriorite($score));
    }

    /**
     * @test
     * Score = 10 → frontière MODÉRÉ (10 ≤ score ≤ 13)
     */
    public function testSeuilFrontiereModere(): void
    {
        // Fiabilité D (1) × Crédibilité 1 (4) = 4, + Immédiat (3) + Moyen (2) + À compléter (2) = 11 — réduire
        // Fiabilité D (1) × Crédibilité 2 (3) = 3, + 72h (2) + Moyen (2) + Actionnable (3) = 10 ✓
        $alert = $this->buildAlert(
            FiabiliteSource::D,
            credibilite: 2,
            AlertUrgence::SOIXANTE_DOUZE_H,
            AlertImpact::MOYEN,
            AlertExploitabilite::ACTIONNABLE
        );

        $score = $this->calculator->computeScore($alert);
        $this->assertEquals(10, $score);
        $this->assertEquals(NiveauPriorite::MODERE, $this->calculator->resolveNiveauPriorite($score));
    }

    /**
     * @test
     * Score = 9 → FAIBLE (≤ 9)
     */
    public function testSeuilFrontiereFaible(): void
    {
        // Fiabilité D (1) × Crédibilité 2 (3) = 3, + Routine (1) + Moyen (2) + Actionnable (3) = 9 ✓
        $alert = $this->buildAlert(
            FiabiliteSource::D,
            credibilite: 2,
            AlertUrgence::ROUTINE,
            AlertImpact::MOYEN,
            AlertExploitabilite::ACTIONNABLE
        );

        $score = $this->calculator->computeScore($alert);
        $this->assertEquals(9, $score);
        $this->assertEquals(NiveauPriorite::FAIBLE, $this->calculator->resolveNiveauPriorite($score));
    }

    /**
     * @test
     * isScoreable() retourne false si un critère manque — pas de calcul partiel.
     */
    public function testIncompleteAlertNotScoreable(): void
    {
        $alert = new Alert();
        $alert->setFiabiliteSource(FiabiliteSource::A);
        // credibiliteContenu non renseigné → pas scoreable

        $this->assertFalse($alert->isScoreable());
        $changed = $this->calculator->calculate($alert);
        $this->assertFalse($changed);
        $this->assertNull($alert->getScoreGei());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helper
    // ─────────────────────────────────────────────────────────────────────────

    private function buildAlert(
        FiabiliteSource     $fiabilite,
        int                 $credibilite,
        AlertUrgence        $urgence,
        AlertImpact         $impact,
        AlertExploitabilite $exploitabilite,
    ): Alert {
        $alert = new Alert();
        $alert->setFiabiliteSource($fiabilite);
        $alert->setCredibiliteContenu($credibilite);
        $alert->setUrgence($urgence);
        $alert->setImpact($impact);
        $alert->setExploitabilite($exploitabilite);
        return $alert;
    }
}
