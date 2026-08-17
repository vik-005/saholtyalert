<?php

namespace App\Form;

use App\Entity\Alert;
use App\Entity\Market;
use App\Enum\AlertCategorie;
use App\Enum\AnonymisationNiveau;
use App\Enum\FiabiliteSource;
use App\Enum\TypeAlerte;
use App\Enum\TypeSource;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire AGENT — Sections 1 à 5 de la Fiche Standard GEI (Annexe A).
 *
 * RÈGLE : Tous les champs Enum utilisent EnumType (pas ChoiceType) pour que
 * Symfony transforme automatiquement la valeur string soumise en objet Enum.
 * Utiliser ChoiceType avec choices() sur un champ Enum provoque :
 *   "Object of class App\Enum\XYZ could not be converted to string"
 */
class AlertType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // ── SECTION 1 — Identification & traçabilité ────────────────────────────
            ->add('market', EntityType::class, [
                'class'        => Market::class,
                'choice_label' => 'nom',
                'label'        => 'Zone (Pays / Marché)',
                'placeholder'  => 'Sélectionnez un pays',
                'attr'         => ['class' => 'form-select'],
            ])
            ->add('portCorridor', TextType::class, [
                'label'    => 'Port / Corridor / Frontière',
                'required' => false,
                'attr'     => [
                    'class'       => 'form-input',
                    'placeholder' => 'ex. Corridor Cotonou - Niamey, Port de Lomé',
                ],
            ])
            ->add('categorie', EnumType::class, [
                'class'       => AlertCategorie::class,
                'label'       => 'Catégorie de l\'alerte',
                'placeholder' => 'Sélectionnez une catégorie',
                'required'    => false,
                'choice_label' => fn(AlertCategorie $c) => $c->label(),
                'attr'        => ['class' => 'form-select'],
            ])

            // ── SECTION 2 — Résumé exécutif ────────────────────────────────────────
            ->add('resumeExecutif', TextareaType::class, [
                'label' => 'Résumé exécutif (max 500 car.)',
                'attr'  => [
                    'class'       => 'form-textarea',
                    'rows'        => 4,
                    'maxlength'   => 500,
                    'placeholder' => 'Synthèse claire et précise des faits constatés (5 lignes max)…',
                ],
            ])

            // ── SECTION 3 — Source & fiabilité ─────────────────────────────────────
            ->add('typeSource', EnumType::class, [
                'class'        => TypeSource::class,
                'label'        => 'Type de source',
                'required'     => false,
                'placeholder'  => 'Sélectionnez le type de source',
                'choice_label' => fn(TypeSource $t) => $t->label(),
                'attr'         => ['class' => 'form-select'],
            ])
            ->add('typeAlerte', EnumType::class, [
                'class'        => TypeAlerte::class,
                'label'        => 'Type d\'alerte',
                'required'     => false,
                'placeholder'  => 'Sélectionnez le type d\'alerte',
                'choice_label' => fn(TypeAlerte $t) => $t->label(),
                'attr'         => ['class' => 'form-select'],
            ])
            ->add('anonymisation', EnumType::class, [
                'class'        => AnonymisationNiveau::class,
                'label'        => 'Niveau d\'anonymisation de la source',
                'choice_label' => fn(AnonymisationNiveau $a) => $a->label(),
                'attr'         => ['class' => 'form-select'],
            ])
            ->add('historiqueSource', TextareaType::class, [
                'label'    => 'Historique de la source',
                'required' => false,
                'attr'     => [
                    'class'       => 'form-textarea',
                    'rows'        => 3,
                    'placeholder' => 'Comportement passé, fiabilité historique, nombre de signalements confirmés…',
                ],
            ])
            ->add('fiabiliteSource', EnumType::class, [
                'class'        => FiabiliteSource::class,
                'label'        => 'Fiabilité de la source (A–D)',
                'required'     => false,
                'placeholder'  => 'Sélectionnez la fiabilité',
                'choice_label' => fn(FiabiliteSource $f) => $f->value . ' — ' . $f->label(),
                'attr'         => [
                    'class' => 'form-select',
                    'title' => 'A = source connue et fiable, D = source non vérifiée',
                ],
                'help' => 'Déclaration terrain : vous seul connaissez votre source. Ce champ nourrit le score GEI (Annexe C).',
            ])

            // ── SECTION 4 — Éléments factuels ──────────────────────────────────────
            ->add('elementsFactuels', TextareaType::class, [
                'label'    => 'Éléments factuels (faits vérifiés)',
                'required' => false,
                'attr'     => [
                    'class'       => 'form-textarea',
                    'rows'        => 6,
                    'placeholder' => 'Description détaillée des faits matériels, dates, conteneurs, marques, volumes…',
                ],
                'help' => 'Faits bruts uniquement. Ne pas mélanger avec des hypothèses.',
            ])
            ->add('referenceDocumentaire', TextType::class, [
                'label'    => 'Référence documentaire',
                'required' => false,
                'attr'     => [
                    'class'       => 'form-input',
                    'placeholder' => 'ex. ALT-BJA02-2026-001',
                ],
                'help' => 'Code de classement interne propre à votre poste. Champ texte libre optionnel.',
            ])

            // ── SECTION 5 — Hypothèses analytiques ─────────────────────────────────
            ->add('hypothesesAnalytiques', TextareaType::class, [
                'label'    => 'Hypothèses analytiques',
                'required' => false,
                'attr'     => [
                    'class'       => 'form-textarea',
                    'rows'        => 4,
                    'placeholder' => 'Hypothèses sur les réseaux, modus operandi, destinations probables…',
                ],
                'help' => 'Distinct des faits. Le Manager peut compléter lors de la qualification.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Alert::class]);
    }
}
