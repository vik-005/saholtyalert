<?php

namespace App\Form;

use App\Entity\Alert;
use App\Entity\User;
use App\Enum\AlertExploitabilite;
use App\Enum\AlertImpact;
use App\Enum\AlertUrgence;
use App\Enum\Recommandation;
use App\Enum\Sensibilite;
use App\Enum\TransmissionStatut;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire Manager — Sections 6, 7 et 8 de la Fiche Standard GEI (Annexe A).
 *
 * RÈGLE : Tous les champs dont la propriété PHP est typée Enum utilisent EnumType.
 * Exception : credibiliteContenu est un int → ChoiceType reste correct.
 */
class QualificationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // ── SECTION 6 — Qualification GEI ──────────────────────────────────────
            ->add('credibiliteContenu', ChoiceType::class, [
                // credibiliteContenu est un int (1-4), pas un Enum → ChoiceType correct ici
                'choices' => [
                    '1 — Confirmée / Très crédible  (score : 4)' => 1,
                    '2 — Probable / Crédible         (score : 3)' => 2,
                    '3 — Douteuse / Peu crédible     (score : 2)' => 3,
                    '4 — Improbable / Non vérifiable (score : 1)' => 4,
                ],
                'label'       => 'Crédibilité du contenu',
                'placeholder' => 'Sélectionnez la crédibilité',
                'required'    => false,
                'attr'        => ['class' => 'form-select js-score-trigger'],
                'help'        => 'Inversée dans la formule GEI (1 = plus crédible = score 4).',
            ])
            ->add('urgence', EnumType::class, [
                'class'        => AlertUrgence::class,
                'label'        => 'Niveau d\'urgence',
                'placeholder'  => 'Sélectionnez l\'urgence',
                'required'     => false,
                'choice_label' => fn(AlertUrgence $u) => $u->label(),
                'attr'         => ['class' => 'form-select js-score-trigger'],
            ])
            ->add('impact', EnumType::class, [
                'class'        => AlertImpact::class,
                'label'        => 'Impact potentiel',
                'placeholder'  => 'Sélectionnez l\'impact',
                'required'     => false,
                'choice_label' => fn(AlertImpact $i) => $i->label(),
                'attr'         => ['class' => 'form-select js-score-trigger'],
            ])
            ->add('exploitabilite', EnumType::class, [
                'class'        => AlertExploitabilite::class,
                'label'        => 'Exploitabilité opérationnelle',
                'placeholder'  => 'Sélectionnez l\'exploitabilité',
                'required'     => false,
                'choice_label' => fn(AlertExploitabilite $e) => $e->label(),
                'attr'         => ['class' => 'form-select js-score-trigger'],
            ])

            // ── SECTION 7 — Recommandation & action ────────────────────────────────
            ->add('recommandation', EnumType::class, [
                'class'        => Recommandation::class,
                'label'        => 'Recommandation opérationnelle',
                'required'     => false,
                'placeholder'  => 'Sélectionnez une recommandation',
                'choice_label' => fn(Recommandation $r) => $r->label(),
                'attr'         => ['class' => 'form-select'],
            ])
            ->add('actionsEnCours', TextareaType::class, [
                'label'    => 'Actions en cours / à engager',
                'required' => false,
                'attr'     => [
                    'class'       => 'form-textarea',
                    'rows'        => 3,
                    'placeholder' => 'Ce qu\'il reste à faire, tâches assignées, suivi…',
                ],
            ])
            ->add('responsableSuivi', EntityType::class, [
                'class'        => User::class,
                'choice_label' => 'nomComplet',
                'label'        => 'Responsable du suivi',
                'required'     => false,
                'placeholder'  => 'Sélectionnez un responsable',
                'attr'         => ['class' => 'form-select'],
            ])
            ->add('commentaires', TextareaType::class, [
                'label'    => 'Notes & Justification de la qualification',
                'required' => false,
                'attr'     => [
                    'class'       => 'form-textarea',
                    'rows'        => 3,
                    'placeholder' => 'Observations du Manager, besoins complémentaires…',
                ],
            ])

            // ── SECTION 8 — Confidentialité & diffusion ────────────────────────────
            ->add('sensibilite', EnumType::class, [
                'class'        => Sensibilite::class,
                'label'        => 'Niveau de sensibilité',
                'choice_label' => fn(Sensibilite $s) => $s->label(),
                'attr'         => ['class' => 'form-select'],
            ])
            ->add('transmission', EnumType::class, [
                'class'        => TransmissionStatut::class,
                'label'        => 'Statut de transmission',
                'choice_label' => fn(TransmissionStatut $t) => $t->label(),
                'attr'         => ['class' => 'form-select'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Alert::class]);
    }
}
