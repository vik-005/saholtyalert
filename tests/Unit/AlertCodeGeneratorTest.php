<?php

namespace App\Tests\Unit;

use App\Entity\Alert;
use App\Entity\Market;
use App\Service\AlertCodeGeneratorService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires du générateur de code GEI (Partie C du prompt expert).
 *
 * Couvre :
 *  C.1 — Format exact GEI-{ISO3}-{ANNEE}-{SEQ:003}
 *  C.2 — Tests explicites obligatoires :
 *    - Séquence suivante (BEN 2026, dernier = 005 → 006)
 *    - Premier code pays/année (CIV 2026, aucun → 001)
 *    - Passage d'année (BEN 2026 dernier = 087 → BEN 2027 → 001)
 *    - Préfixe GEI- (pas ALT-)
 *  C.3 — Table de correspondance pays → ISO3
 */
class AlertCodeGeneratorTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────────────────
    // C.1 — Format exact GEI-{ISO3}-{ANNEE}-{SEQ:003}
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @test
     * Le préfixe doit être GEI- et non ALT- (correction critique Partie C).
     */
    public function testPrefixeEstGEI(): void
    {
        $generator = $this->makeGenerator(0);
        $alert     = $this->buildAlert('BEN', '2026-01-15');

        $code = $generator->generate($alert);
        $this->assertStringStartsWith('GEI-', $code, 'Le code GEI doit commencer par "GEI-" et non "ALT-"');
    }

    /**
     * @test
     * Format complet : GEI-BEN-2026-001 (aucune alerte existante = séquence démarre à 001)
     */
    public function testFormatCompletPremierCode(): void
    {
        $generator = $this->makeGenerator(0); // maxSeq = 0 → sequence = 1
        $alert     = $this->buildAlert('CIV', '2026-03-20');

        $code = $generator->generate($alert);
        $this->assertEquals('GEI-CIV-2026-001', $code, 'Premier code CIV 2026 doit être GEI-CIV-2026-001');
    }

    /**
     * @test
     * La séquence est sur 3 chiffres avec zéros de tête.
     */
    public function testSequenceTroisChiffresAvecZeros(): void
    {
        $generator = $this->makeGenerator(5); // maxSeq = 5 → sequence = 6
        $alert     = $this->buildAlert('BEN', '2026-08-15');

        $code = $generator->generate($alert);
        $this->assertEquals('GEI-BEN-2026-006', $code);
        $this->assertMatchesRegularExpression('/^GEI-[A-Z]{3}-\d{4}-\d{3}$/', $code);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // C.2 — Test séquence suivante
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @test
     * Dernier code BEN-2026 = GEI-BEN-2026-005 → nouvelle alerte = GEI-BEN-2026-006
     */
    public function testSequenceSuivante005vers006(): void
    {
        $generator = $this->makeGenerator(5); // max = 5 → +1 = 6
        $alert     = $this->buildAlert('BEN', '2026-07-01');

        $code = $generator->generate($alert);
        $this->assertEquals('GEI-BEN-2026-006', $code);
    }

    /**
     * @test
     * Aucune alerte CIV en 2026 → premier code = GEI-CIV-2026-001
     */
    public function testPremierCodeSiAucunExistant(): void
    {
        $generator = $this->makeGenerator(0); // max = 0 → +1 = 1
        $alert     = $this->buildAlert('CIV', '2026-06-10');

        $code = $generator->generate($alert);
        $this->assertEquals('GEI-CIV-2026-001', $code);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // C.2 — Test passage d'année (RESET à 001)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @test
     * Dernier BEN-2026 = GEI-BEN-2026-087.
     * Alerte BEN créée en 2027 → GEI-BEN-2027-001 (reset, pas GEI-BEN-2027-088).
     */
    public function testPassageAnneeResetSequenceA001(): void
    {
        // Première requête (2026) : retourne 87, deuxième (2027) : retourne 0
        $conn = $this->createMock(Connection::class);
        $conn->expects($this->exactly(2))
             ->method('fetchOne')
             ->willReturnOnConsecutiveCalls('87', '0');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        $generator = new AlertCodeGeneratorService($em);

        $alert2026 = $this->buildAlert('BEN', '2026-12-31');
        $code2026  = $generator->generate($alert2026);
        $this->assertEquals('GEI-BEN-2026-088', $code2026, 'Dernier 2026 : 087 → suivant = 088');

        $alert2027 = $this->buildAlert('BEN', '2027-01-01');
        $code2027  = $generator->generate($alert2027);
        $this->assertEquals('GEI-BEN-2027-001', $code2027, 'Premier 2027 doit être 001, pas 088');
    }

    /**
     * @test
     * L'année est prise sur dateCreation, jamais modifiable manuellement.
     * Une alerte créée avec une date 2024 doit produire GEI-...-2024-...
     */
    public function testAnneeExtraiteDeDateCreation(): void
    {
        $generator = $this->makeGenerator(0);
        $alert     = $this->buildAlert('TGO', '2024-11-30');

        $code = $generator->generate($alert);
        $this->assertStringContainsString('-2024-', $code, 'L\'année doit être 2024 d\'après la dateCreation');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // C.1 — Table de correspondance Pays → ISO3
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @test
     * Vérification de la table exacte de correspondance Pays → ISO3.
     */
    public function testTableCorrespondancePaysIso3(): void
    {
        $this->assertEquals('BEN', AlertCodeGeneratorService::paysToIso3('Bénin'));
        $this->assertEquals('BEN', AlertCodeGeneratorService::paysToIso3('Benin'));
        $this->assertEquals('TGO', AlertCodeGeneratorService::paysToIso3('Togo'));
        $this->assertEquals('GHA', AlertCodeGeneratorService::paysToIso3('Ghana'));
        $this->assertEquals('CIV', AlertCodeGeneratorService::paysToIso3("Côte d'Ivoire"));
        $this->assertEquals('MLI', AlertCodeGeneratorService::paysToIso3('Mali'));
        $this->assertEquals('NER', AlertCodeGeneratorService::paysToIso3('Niger'));
        $this->assertNull(AlertCodeGeneratorService::paysToIso3('Autre'), 'Autre → null (saisie manuelle)');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Construit un générateur avec un mock DBAL retournant la valeur max_seq donnée.
     */
    private function makeGenerator(int $maxSeq): AlertCodeGeneratorService
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')->willReturn((string)$maxSeq);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        return new AlertCodeGeneratorService($em);
    }

    /**
     * Construit une alerte minimale avec Market et date.
     */
    private function buildAlert(string $iso3, string $dateStr): Alert
    {
        $market = new Market();
        $market->setCodeIso3($iso3);
        $market->setNom($iso3);

        $alert = new Alert();
        $alert->setMarket($market);
        $alert->setDateCreation(new \DateTime($dateStr));
        $alert->setResumeExecutif('Test');

        return $alert;
    }
}
