<?php

namespace App\Tests\Unit;

use App\Entity\Alert;
use App\Entity\Market;
use App\Entity\User;
use App\Enum\AlertExploitabilite;
use App\Enum\AlertImpact;
use App\Enum\AlertStatut;
use App\Enum\AlertUrgence;
use App\Enum\FiabiliteSource;
use App\Enum\NiveauPriorite;
use App\Enum\Recommandation;
use App\Enum\Sensibilite;
use App\Enum\TypeAlerte;
use App\Enum\TypeLocalisation;
use App\Service\PdfExportService;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class PdfExportServiceTest extends TestCase
{
    private PdfExportService $pdfExportService;

    protected function setUp(): void
    {
        $templatesDir = dirname(__DIR__, 2) . '/templates';
        $loader = new FilesystemLoader($templatesDir);
        $twig = new Environment($loader);

        $this->pdfExportService = new PdfExportService($twig);
    }

    public function testGenerateAlertPdfContainsAllFieldsAndHypotheses(): void
    {
        $user = new User();
        $user->setPrenom('Jean');
        $user->setNom('Dupont');
        $user->setEmail('jean.dupont@gei.test');

        $market = new Market();
        $market->setNom('Bénin');
        $market->setCodeIso3('BEN');

        $alert = new Alert();
        $alert->setCodeGei('GEI-BEN-2026-001');
        $alert->setDateCreation(new \DateTime('2026-08-10'));
        $alert->setEmetteur($user);
        $alert->setMarket($market);
        $alert->setTypeLocalisation(TypeLocalisation::PORT);
        $alert->setPortCorridor('Port Autonome de Cotonou');
        $alert->setCategorie('transit_tabac');
        $alert->setTypeAlerte(TypeAlerte::OPERATIONNELLE);
        $alert->setMarque('FINE 100s');
        $alert->setOperateurActeur('TRANS-LOGISTICS SARL');
        $alert->setResumeExecutif("Saisie de 500 cartons de cigarettes de contrebande.\nCargaison dissimulée.");
        $alert->setTypeSource('Douanes maritimes');
        $alert->setAnonymisation('oui');
        $alert->setFiabiliteSource(FiabiliteSource::A);
        $alert->setCredibiliteContenu(1);
        $alert->setUrgence(AlertUrgence::IMMEDIAT);
        $alert->setImpact(AlertImpact::ELEVE);
        $alert->setExploitabilite(AlertExploitabilite::ACTIONNABLE);
        $alert->setRecommandation(Recommandation::TRANSMISSION);
        $alert->setStatut(AlertStatut::VALIDEE);
        $alert->setSensibilite(Sensibilite::CONFIDENTIELLE);
        $alert->setElementsFactuels("Conteneur n° MSKU-982341-2 inspecté au scanner.\nVolume estimé : 500 cartons.");
        $alert->setHypothesesAnalytiques("Filière transfrontalière approvisionnant le Sahel via Cotonou-Niamey.\nModus operandi : fausse déclaration et double paroi.");
        $alert->setReferenceDocumentaire('DECL-DOUANE-2026-0982');
        $alert->setPiecesDisponibles(true);
        $alert->setPiecesType('Manifeste, photos scanner, bordereau');
        $alert->setActionsEnCours('Transmission urgente au parquet et notification au point focal Niger.');
        $alert->setScoreGei(25);
        $alert->setNiveauPriorite(NiveauPriorite::CRITIQUE);

        $pdfOutput = $this->pdfExportService->generateAlertPdf($alert);

        $this->assertNotEmpty($pdfOutput);
        $this->assertStringStartsWith('%PDF-', $pdfOutput, 'Le document généré doit être un flux PDF valide.');
    }
}
