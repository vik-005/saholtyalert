<?php

namespace App\Form;

use App\Entity\RuleConfig;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class RuleConfigType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('valeur', TextType::class, [
                'label' => 'Valeur du seuil',
                'attr' => ['class' => 'form-input'],
            ])
            ->add('description', TextType::class, [
                'label' => 'Description de la règle',
                'attr' => ['class' => 'form-input'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RuleConfig::class,
        ]);
    }
}
