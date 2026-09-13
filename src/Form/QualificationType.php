<?php

namespace App\Form;

use App\Entity\Alert;
use App\Entity\ListeReferenceValeur;
use App\Entity\User;
use App\Enum\AlertExploitabilite;
use App\Enum\AlertImpact;
use App\Enum\AlertUrgence;
use App\Enum\FiabiliteSource;
use App\Enum\Recommandation;
use App\Enum\Sensibilite;
use App\Enum\TransmissionStatut;
use App\Repository\ListeReferenceValeurRepository;
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
 *
 * La Fiabilité (fiabiliteSource) est incluse car elle est un critère de scoring
 * que le Manager doit pouvoir consulter et éventuellement ajuster lors de la
 * qualification (STEP 5 du workflow métier).
 */
class QualificationType extends AbstractType
{
    private ListeReferenceValeurRepository $lrRepo;

    public function __construct(ListeReferenceValeurRepository $lrRepo)
    {
        $this->lrRepo = $lrRepo;
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Listes de valeurs pré-enregistrées (categories et types de source)
        $categoriesListe = $this->lrRepo->findActivesByType('categorie');
        $typeSourceListe = $this->lrRepo->findActivesByType('type_source');

        $builder
            // ── SECTION 5 — Qualification GEI (Annexe C) ────────────────────────────
            // Fiabilité de la source — critique pour le calcul du score
            ->add('fiabiliteSource', EnumType::class, [
                'class'        => FiabiliteSource::class,
                'label'        => 'Fiabilité de la source (A–D)',
                'required'     => false,
                'placeholder'  => 'Sélectionnez la fiabilité',
                'choice_label' => fn(FiabiliteSource $f) => $f->value . ' — ' . $f->label(),
                'attr'         => [
                    'class' => 'form-select js-score-trigger',
                    'title' => 'A = source connue et fiable (score 4) … D = source non vérifiée (score 1)',
                ],
                'help' => 'A = source connue et fiable, D = source non vérifiée. Ce champ nourrit le score GEI (Annexe C).',
            ])
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
                'label'        => 'Impact potentiel (Élevé, Moyen ou Faible)',
                'placeholder'  => 'Sélectionnez un niveau d\'impact',
                'required'     => false,
                'choice_label' => fn(AlertImpact $i) => $i->label(),
                'attr'         => ['class' => 'form-select js-score-trigger'],
                'help'         => 'Élevé = 3 points, Moyen = 2 points, Faible = 1 point.',
            ])
            ->add('exploitabilite', EnumType::class, [
                'class'        => AlertExploitabilite::class,
                'label'        => 'Exploitabilité opérationnelle',
                'placeholder'  => 'Sélectionnez l\'exploitabilité',
                'required'     => false,
                'choice_label' => fn(AlertExploitabilite $e) => $e->label(),
                'attr'         => ['class' => 'form-select js-score-trigger'],
            ])

            // ── SECTION 6 — Recommandation & action ────────────────────────────────
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
            ])

            // ── Champ de rejet (utilisé uniquement pour le rejet) ──
            ->add('commentaireRejet', TextareaType::class, [
                'label'    => 'Commentaire de rejet (obligatoire si rejet)',
                'required' => false,
                'attr'     => [
                    'class'       => 'form-textarea',
                    'rows'        => 3,
                    'placeholder' => 'Expliquez pourquoi la fiche est rejetée…',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Alert::class]);
    }
}
