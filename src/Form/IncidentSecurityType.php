<?php

namespace App\Form;

use App\Entity\IncidentSecurity;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class IncidentSecurityType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('typeIncident', ChoiceType::class, [
                'choices' => [
                    'Violation de confidentialité de données' => 'violation_donnees',
                    'Accès physique ou logique non autorisé' => 'acces_non_autorise',
                    'Fuite d\'information vers l\'extérieur' => 'fuite_information',
                    'Perte ou vol de matériel / identifiants' => 'perte_materiel',
                    'Autre incident de sécurité' => 'autre',
                ],
                'label' => 'Type d\'incident (Annexe D §10)',
                'attr' => ['class' => 'form-select'],
            ])
            ->add('gravite', ChoiceType::class, [
                'choices' => [
                    'Faible — Sans impact direct' => 'faible',
                    'Modérée — Impact interne limité' => 'modere',
                    'Élevée — Compromission de source / alerte' => 'eleve',
                    'Critique — Danger immédiat / fuite majeure' => 'critique',
                ],
                'label' => 'Niveau de gravité',
                'attr' => ['class' => 'form-select'],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description circonstanciée de l\'incident',
                'attr' => ['class' => 'form-textarea', 'rows' => 5, 'placeholder' => 'Dates, heures, personnes impliquées, nature des données compromises...'],
            ])
            ->add('mesuresCorrectives', TextareaType::class, [
                'label' => 'Mesures d\'urgence ou d\'atténuation déjà prises',
                'required' => false,
                'attr' => ['class' => 'form-textarea', 'rows' => 3],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => IncidentSecurity::class,
        ]);
    }
}
