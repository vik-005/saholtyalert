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
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class UserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var User|null $currentUser */
        $currentUser = $options['current_user'];
        $isManager = $currentUser !== null && $currentUser->getRole() === UserRoleEnum::PFT;

        $roleChoices = $isManager
            ? [UserRoleEnum::EMETTEUR_TERRAIN->label() => UserRoleEnum::EMETTEUR_TERRAIN->value]
            : UserRoleEnum::choices();

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
                'choices' => $roleChoices,
                'label' => 'Rôle applicatif GEI',
                'attr' => ['class' => 'form-select'],
                'data' => $isManager ? UserRoleEnum::EMETTEUR_TERRAIN->value : null,
            ]);

        if ($isManager) {
            $managedMarkets = $currentUser->getAllManagedMarkets();
            $managedIds = array_map(fn($m) => $m->getId(), $managedMarkets);

            // Marché principal (legacy — compat)
            $builder->add('market', EntityType::class, [
                'class' => Market::class,
                'choice_label' => 'nom',
                'label' => 'Marché principal de rattachement',
                'required' => false,
                'placeholder' => 'Aucun marché principal (multi-marchés uniquement)',
                'query_builder' => function (\Doctrine\ORM\EntityRepository $er) use ($managedIds) {
                    return $er->createQueryBuilder('m')
                        ->where('m.id IN (:ids)')
                        ->setParameter('ids', !empty($managedIds) ? $managedIds : [0]);
                },
                'attr' => ['class' => 'form-select'],
                'help' => 'Optionnel si vous assignez l\'Agent à plusieurs marchés ci-dessous.',
            ]);

            // Multi-marchés agent (Partie C)
            $builder->add('agentMarkets', EntityType::class, [
                'class' => Market::class,
                'choice_label' => 'nom',
                'label' => 'Marchés assignés à cet Agent',
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'query_builder' => function (\Doctrine\ORM\EntityRepository $er) use ($managedIds) {
                    return $er->createQueryBuilder('m')
                        ->where('m.id IN (:ids)')
                        ->setParameter('ids', !empty($managedIds) ? $managedIds : [0]);
                },
                'attr' => ['class' => 'agent-markets-checkboxes'],
                'help' => 'Cochez un ou plusieurs marchés parmi ceux que vous supervisez. L\'Agent ne pourra soumettre des alertes que sur ces marchés.',
                'by_reference' => false,
            ]);
        } else {
            $builder->add('market', EntityType::class, [
                'class' => Market::class,
                'choice_label' => 'nom',
                'label' => 'Marché / Pays de rattachement',
                'required' => false,
                'placeholder' => 'Aucun (Rôle régional / global)',
                'attr' => ['class' => 'form-select'],
            ]);
        }

        $builder
            ->add('plainPassword', PasswordType::class, [
                'label' => 'Mot de passe',
                'mapped' => false,
                'required' => $options['is_new'],
                'constraints' => [
                    new Assert\NotBlank(message: 'Le mot de passe est obligatoire.', groups: ['registration']),
                    new Assert\Length(min: 12, minMessage: 'Le mot de passe doit contenir au moins {{ limit }} caractères.', groups: ['registration']),
                    new Assert\Regex('/[A-Z]/', message: 'Le mot de passe doit contenir au moins une majuscule.', groups: ['registration']),
                    new Assert\Regex('/[a-z]/', message: 'Le mot de passe doit contenir au moins une minuscule.', groups: ['registration']),
                    new Assert\Regex('/\d/', message: 'Le mot de passe doit contenir au moins un chiffre.', groups: ['registration']),
                ],
                'attr' => ['class' => 'form-input', 'autocomplete' => 'new-password'],
            ])
            ->add('actif', CheckboxType::class, [
                'label' => 'Compte utilisateur actif',
                'required' => false,
                'attr' => ['class' => 'form-checkbox'],
            ]);

        // Transformer string ↔ UserRoleEnum
        $builder->get('role')->addModelTransformer(new CallbackTransformer(
            fn (?UserRoleEnum $role) => $role?->value,
            fn (?string $roleValue) => $roleValue ? UserRoleEnum::from($roleValue) : null,
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'is_new' => true,
            'current_user' => null,
            'validation_groups' => ['registration'],
        ]);
    }
}
