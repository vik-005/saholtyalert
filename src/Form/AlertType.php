<?php

namespace App\Form;

use App\Entity\Alert;
use App\Entity\ListeReferenceValeur;
use App\Entity\Market;
use App\Entity\User;
use App\Enum\FiabiliteSource;
use App\Enum\AlertUrgence;
use App\Enum\AlertImpact;
use App\Enum\AlertExploitabilite;
use App\Enum\Recommandation;
use App\Enum\TypeAlerte;
use App\Repository\ListeReferenceValeurRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire AGENT — Sections 1 à 5 de la Fiche Standard GEI (Annexe A).
 * À partir du prompt expert final, l'Agent remplit désormais l'ENTIÈRE fiche,
 * y compris les 5 critères de qualification (Section 6) → score calculé automatiquement.
 *
 * Champs categorie et typeSource : utilisent les valeurs pré-enregistrées dans la DB
 * via la table liste_reference_valeur (pas de saisie libre).
 *
 * RÈGLE : Tous les champs Enum utilisent EnumType (pas ChoiceType) pour que
 * Symfony transforme automatiquement la valeur string soumise en objet Enum.
 * Utiliser ChoiceType avec choices() sur un champ Enum provoque :
 *   "Object of class App\Enum\XYZ could not be converted to string"
 */
class AlertType extends AbstractType
{
    private ListeReferenceValeurRepository $lrRepo;

    public function __construct(ListeReferenceValeurRepository $lrRepo)
    {
        $this->lrRepo = $lrRepo;
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var User|null $currentUser */
        $currentUser = $options['current_user'];
        $agentMarkets = [];

        if ($currentUser !== null) {
            $agentMarkets = $currentUser->getAllAgentMarkets();
        }

        // Si l'Agent a plusieurs marchés assignés, on limite les choix aux siens.
        // Si un seul marché, il est pré-sélectionné (mais toujours modifiable si recréation).
        $hasMultipleMarkets = count($agentMarkets) > 1;

        // Listes de valeurs pré-enregistrées (categories et types de source)
        $categoriesListe = $this->lrRepo->findActivesByType('categorie');
        $typeSourceListe = $this->lrRepo->findActivesByType('type_source');

        $builder
            // ── SECTION 1 — Identification & traçabilité ────────────────────────────
            ->add('market', EntityType::class, [
                'class'        => Market::class,
                'choice_label' => 'nom',
                'label'        => 'Zone (Pays / Marché)',
                'placeholder'  => $hasMultipleMarkets ? 'Sélectionnez le marché concerné' : 'Sélectionnez un pays',
                'attr'         => ['class' => 'form-select'],
                'query_builder' => function (\Doctrine\ORM\EntityRepository $er) use ($agentMarkets) {
                    if (!empty($agentMarkets)) {
                        $ids = array_map(fn($m) => $m->getId(), $agentMarkets);
                        return $er->createQueryBuilder('m')
                            ->where('m.id IN (:ids)')
                            ->setParameter('ids', $ids)
                            ->orderBy('m.nom', 'ASC');
                    }
                    // Pas de restriction (SUPERADMIN, SAHOLTY, etc.) → tous les marchés
                    return $er->createQueryBuilder('m')->orderBy('m.nom', 'ASC');
                },
                'help' => $hasMultipleMarkets
                    ? 'Vous êtes rattaché à plusieurs marchés. Sélectionnez celui qui concerne cette alerte.'
                    : null,
            ])
            ->add('portCorridor', TextType::class, [
                'label'    => 'Port / Corridor / Frontière',
                'required' => false,
                'attr'     => [
                    'class'       => 'form-input',
                    'placeholder' => 'ex. Corridor Cotonou - Niamey, Port de Lomé',
                ],
            ])
            // ── CHAMP CATEGORIE : utilise les valeurs pré-enregistrées dans la DB ──
            ->add('categorie', EntityType::class, [
                'class'        => ListeReferenceValeur::class,
                'choice_label' => 'libelle',
                'label'        => 'Catégorie de l\'alerte',
                'required'     => true,
                'placeholder'  => 'Sélectionnez une catégorie',
                'query_builder' => function (\Doctrine\ORM\EntityRepository $er) {
                    return $er->createQueryBuilder('l')
                        ->where('l.typeListe = :type')
                        ->andWhere('l.actif = true')
                        ->setParameter('type', 'categorie')
                        ->orderBy('l.ordreAffichage', 'ASC')
                        ->addOrderBy('l.libelle', 'ASC');
                },
                'attr' => ['class' => 'form-select'],
                'help' => 'Les catégories sont pré-configurées dans l\'administration.',
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
            // ── CHAMP TYPE SOURCE : utilise les valeurs pré-enregistrées dans la DB ──
            ->add('typeSource', EntityType::class, [
                'class'        => ListeReferenceValeur::class,
                'choice_label' => 'libelle',
                'label'        => 'Type de source',
                'required'     => true,
                'placeholder'  => 'Sélectionnez un type de source',
                'query_builder' => function (\Doctrine\ORM\EntityRepository $er) {
                    return $er->createQueryBuilder('l')
                        ->where('l.typeListe = :type')
                        ->andWhere('l.actif = true')
                        ->setParameter('type', 'type_source')
                        ->orderBy('l.ordreAffichage', 'ASC')
                        ->addOrderBy('l.libelle', 'ASC');
                },
                'attr' => ['class' => 'form-select'],
                'help' => 'Les types de source sont pré-configurés dans l\'administration.',
            ])
            ->add('typeAlerte', EnumType::class, [
                'class'        => TypeAlerte::class,
                'label'        => 'Type d\'alerte',
                'required'     => false,
                'placeholder'  => 'Sélectionnez le type d\'alerte',
                'choice_label' => fn(TypeAlerte $t) => $t->label(),
                'attr'         => ['class' => 'form-select'],
            ])
            ->add('anonymisation', ChoiceType::class, [
                'choices' => [
                    'Oui' => 'oui',
                    'Non' => 'non',
                ],
                'label'        => 'Niveau d\'anonymisation de la source',
                'placeholder'  => 'Sélectionnez',
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
            ])

            // ── SECTION 6 — QUALIFICATION GEI (NOUVEAU — Agent remplit maintenant) ───
            // Crédibilité (1-4, inversée : 1=plus crédible=score 4)
            ->add('credibiliteContenu', ChoiceType::class, [
                'choices' => [
                    '1 — Confirmée / Très crédible (score : 4)' => 1,
                    '2 — Probable / Crédible       (score : 3)' => 2,
                    '3 — Douteuse / Peu crédible   (score : 2)' => 3,
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
            ->add('commentaires', TextareaType::class, [
                'label'    => 'Notes & Justification de la qualification',
                'required' => false,
                'attr'     => [
                    'class'       => 'form-textarea',
                    'rows'        => 3,
                    'placeholder' => 'Observations de l\'Agent, besoins complémentaires…',
                ],
            ])
            ->add('operateurActeur', TextType::class, [
                'label'    => 'Opérateur / Acteur',
                'required' => false,
                'attr'     => [
                    'class'       => 'form-input',
                    'placeholder' => 'ex. Point focal Douanes Bénin, Agent terrain…',
                ],
                'help' => 'Nom de l\'opérateur ou acteur terrain associé à cette alerte.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'   => Alert::class,
            'current_user' => null,
        ]);
        $resolver->setAllowedTypes('current_user', ['null', User::class]);
    }
}
