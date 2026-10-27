<?php

namespace App\Form;

use App\Entity\AlertTransmission;
use App\Repository\ListeReferenceValeurRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TransmissionType extends AbstractType
{
    public function __construct(
        private readonly ListeReferenceValeurRepository $lrRepo,
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $choices = [
            'Direction Générale des Douanes (DGD)' => 'DGD',
            'Police Nationale / Interpol' => 'POLICE',
            'Gendarmerie Nationale' => 'GENDARMERIE',
            'Administration Fiscale / Impôts' => 'FISC',
            'Partenaire International (Interpol / WCO)' => 'PARTENAIRE_INT',
            'Autorité Portuaire / Maritime' => 'PORT',
            'Autre autorité compétente' => 'AUTRE',
        ];

        // Institutions de transition / transmission configurées manuellement par l'admin
        $customInstitutions = $this->lrRepo->findActivesByType('institution_transmission');
        foreach ($customInstitutions as $inst) {
            $label = $inst->getLibelle();
            $choices[$label] = $label;
        }

        $builder
            ->add('destinataire', ChoiceType::class, [
                'choices' => $choices,
                'label' => 'Destinataire institutionnel',
                'attr' => ['class' => 'form-select'],
            ])
            ->add('noteTransmission', TextareaType::class, [
                'label' => 'Note d\'accompagnement / Recommandations de transmission',
                'required' => false,
                'attr' => ['class' => 'form-textarea', 'rows' => 4, 'placeholder' => 'Préciser les éléments clés transmis et les demandes de retour d\'information...'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AlertTransmission::class,
        ]);
    }
}
