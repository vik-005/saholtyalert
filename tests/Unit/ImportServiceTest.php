<?php

namespace App\Tests\Unit;

use App\Entity\Alert;
use App\Entity\User;
use App\Enum\AlertExploitabilite;
use App\Enum\AlertImpact;
use App\Enum\AlertLegality;
use App\Enum\AlertUrgence;
use App\Enum\FiabiliteSource;
use App\Enum\Recommandation;
use App\Enum\TypeAlerte;
use App\Enum\TypeLocalisation;
use App\Form\ImportType;
use App\Repository\AlertRepository;
use App\Repository\MarketRepository;
use App\Repository\UserRepository;
use App\Service\AlertCodeGeneratorService;
use App\Service\ImportService;
use App\Service\ScoreCalculatorService;
use App\Service\ExportService;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Constraints\File;

class ImportServiceTest extends TestCase
{
    public function testUploadAcceptsOnlyTheFormatUsedByTheXlsxReader(): void
    {
        $validator = Validation::createValidator();
        $formFactory = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension($validator))
            ->getFormFactory();
        $form = $formFactory->create(ImportType::class);
        $constraints = $form->get('file')->getConfig()->getOption('constraints');
        $fileConstraint = current(array_filter($constraints, static fn ($constraint) => $constraint instanceof File));

        self::assertInstanceOf(File::class, $fileConstraint);
        self::assertSame(
            ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            $fileConstraint->mimeTypes
        );
    }

    public function testExportHeadersCoverTheRequiredImportedColumnsWithoutRequiringNonImportedMetadata(): void
    {
        $service = $this->createImportService();
        $headerRow = [];
        $nonImportedColumns = [
            'Score GEI',
            'Niveau de priorité',
            'Agent soumetteur',
            'Manager validateur',
            'Date de validation',
            'Délai traitement (h)',
        ];

        foreach (ExportService::COLUMNS as $index => $header) {
            if (!in_array($header, $nonImportedColumns, true)) {
                $headerRow[Coordinate::stringFromColumnIndex($index + 1)] = $header;
            }
        }

        [$columnMap, $errors] = $this->invokePrivate($service, 'detectColumns', [$headerRow]);

        self::assertSame([], $errors);
        self::assertArrayHasKey('derniere_maj', $columnMap);
    }

    public function testNewAlertFieldsAndExportedUpdateTimestampMapBackToAlert(): void
    {
        $service = $this->createImportService();
        $headerRow = [];
        $row = [];
        foreach (ExportService::COLUMNS as $index => $header) {
            $column = Coordinate::stringFromColumnIndex($index + 1);
            $headerRow[$column] = $header;
            $row[$column] = '';
        }

        [$columnMap, $errors] = $this->invokePrivate($service, 'detectColumns', [$headerRow]);
        self::assertSame([], $errors);
        self::assertSame('AN', $columnMap['legal_status']);

        $values = [
            'Date' => '16/10/2026',
            'Zone (pays)' => 'Bénin',
            'Résumé court' => 'Résumé d’import',
            'Fiabilité (A–D)' => FiabiliteSource::cases()[0]->value,
            'Crédibilité (1–4)' => '3',
            'Urgence' => AlertUrgence::cases()[0]->label(),
            'Impact' => AlertImpact::cases()[0]->label(),
            'Exploitabilité' => AlertExploitabilite::cases()[0]->label(),
            'Opérateur / Acteur' => 'Opérateur Alpha',
            'Marque' => 'Marque Exemple',
            'Éléments factuels' => 'Faits observés',
            'Hypothèses analytiques' => 'Hypothèse importée',
            'Type de localisation' => 'Aéroport',
            'Type d\'alerte' => 'Stratégique',
            'Recommandation' => Recommandation::APPROFONDIR->label(),
            'Historique de la source' => 'Historique importé',
            'Décision GEI' => 'Décision importée',
            'Parcours pays' => 'BJ, TG',
            'Dernière MAJ' => '15/10/2026 18:42',
            'Statut juridique' => AlertLegality::ILLICITE->label(),
        ];
        foreach ($values as $header => $value) {
            $column = array_search($header, ExportService::COLUMNS, true);
            self::assertNotFalse($column, sprintf('Missing export header "%s".', $header));
            $row[Coordinate::stringFromColumnIndex($column + 1)] = $value;
        }

        $alert = new Alert();
        $missing = $this->invokePrivate($service, 'fillAlertFromRow', [$alert, $row, $columnMap, new User()]);

        self::assertSame([], $missing);
        self::assertSame('Opérateur Alpha', $alert->getOperateurActeur());
        self::assertSame('Marque Exemple', $alert->getMarque());
        self::assertSame('Faits observés', $alert->getElementsFactuels());
        self::assertSame('Hypothèse importée', $alert->getHypothesesAnalytiques());
        self::assertSame(TypeLocalisation::AEROPORT, $alert->getTypeLocalisation());
        self::assertSame(TypeAlerte::STRATEGIQUE, $alert->getTypeAlerte());
        self::assertSame(Recommandation::APPROFONDIR, $alert->getRecommandation());
        self::assertSame('Historique importé', $alert->getHistoriqueSource());
        self::assertSame('Décision importée', $alert->getDecisionGei());
        self::assertSame(['BJ', 'TG'], $alert->getParcoursCountries());
        self::assertSame(AlertLegality::ILLICITE, $alert->getLegalStatus());
        self::assertSame('2026-10-15 18:42:00', $alert->getUpdatedAt()->format('Y-m-d H:i:s'));

        $alert->onPrePersist();
        self::assertSame('2026-10-15 18:42:00', $alert->getUpdatedAt()->format('Y-m-d H:i:s'));
        $alert->onPreUpdate();
        self::assertNotSame('2026-10-15 18:42:00', $alert->getUpdatedAt()->format('Y-m-d H:i:s'));
    }

    private function createImportService(): ImportService
    {
        return new ImportService(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(MarketRepository::class),
            $this->createMock(UserRepository::class),
            $this->createMock(AlertRepository::class),
            $this->createMock(ScoreCalculatorService::class),
            $this->createMock(AlertCodeGeneratorService::class),
        );
    }

    private function invokePrivate(object $object, string $method, array $arguments): mixed
    {
        return (new \ReflectionMethod($object, $method))->invokeArgs($object, $arguments);
    }
}
