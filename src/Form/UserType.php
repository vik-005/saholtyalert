<?php

namespace App\Form;

use App\Entity\Market;
use App\Entity\User;
use App\Enum\UserRoleEnum;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class UserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('prenom', TextType::class, [
                'label' => 'Prénom',
                'attr' => ['class' => 'form-input'],
            ])
            ->add('nom', TextType::class, [
                'label' => 'Nom de famille',
                'attr' => ['class' => 'form-input'],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Adresse email professionnelle',
                'attr' => ['class' => 'form-input'],
            ])
            ->add('telephone', TextType::class, [
                'label' => 'Téléphone',
                'required' => false,
                'attr' => ['class' => 'form-input'],
            ])
            ->add('fonction', TextType::class, [
                'label' => 'Fonction / Titre',
                'required' => false,
                'attr' => ['class' => 'form-input'],
            ])
            ->add('role', ChoiceType::class, [
                'choices' => UserRoleEnum::choices(),
                'label' => 'Rôle applicatif GEI',
                'attr' => ['class' => 'form-select'],
            ])
            ->add('market', EntityType::class, [
                'class' => Market::class,
                'choice_label' => 'nom',
                'label' => 'Marché / Pays de rattachement',
                'required' => false,
                'placeholder' => 'Aucun (Rôle régional / global)',
                'attr' => ['class' => 'form-select'],
            ])
            ->add('plainPassword', PasswordType::class, [
                'label' => 'Mot de passe',
                'mapped' => false,
                'required' => $options['is_new'],
                'attr' => ['class' => 'form-input', 'autocomplete' => 'new-password'],
            ])
            ->add('actif', CheckboxType::class, [
                'label' => 'Compte utilisateur actif',
                'required' => false,
                'attr' => ['class' => 'form-checkbox'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'is_new' => true,
        ]);
    }
}
