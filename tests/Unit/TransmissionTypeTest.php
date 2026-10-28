<?php

namespace App\Tests\Unit;

use App\Entity\AlertTransmission;
use App\Entity\ListeReferenceValeur;
use App\Form\TransmissionType;
use App\Repository\ListeReferenceValeurRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Forms;

class TransmissionTypeTest extends TestCase
{
    public function testFormUsesActiveInstitutionLabelsAndKeepsLegacyValues(): void
    {
        $customInstitution = (new ListeReferenceValeur())
            ->setTypeListe('institution_transmission')
            ->setLibelle('Office régional de coordination');
        $legacyInstitution = (new ListeReferenceValeur())
            ->setTypeListe('institution_transmission')
            ->setLibelle('Direction Générale des Douanes (DGD)')
            ->setValeur('DGD');

        $repository = $this->createMock(ListeReferenceValeurRepository::class);
        $repository->expects(self::once())
            ->method('findActivesByType')
            ->with('institution_transmission')
            ->willReturn([$legacyInstitution, $customInstitution]);

        $formFactory = Forms::createFormFactoryBuilder()
            ->addType(new TransmissionType($repository))
            ->getFormFactory();
        $form = $formFactory->create(TransmissionType::class, (new AlertTransmission())->setDestinataire('DGD'));
        $choices = $form->get('destinataire')->getConfig()->getOption('choices');

        self::assertSame('DGD', $choices['Direction Générale des Douanes (DGD)']);
        self::assertSame('Office régional de coordination', $choices['Office régional de coordination']);
        self::assertCount(2, $choices);
    }
}
