<?php

namespace App\Service;

use App\Entity\Alert;
use App\Entity\AlertQualificationHistory;
use App\Enum\AlertExploitabilite;
use App\Enum\AlertImpact;
use App\Enum\AlertUrgence;
use App\Enum\FiabiliteSource;
use App\Enum\NiveauPriorite;
use App\Repository\RuleConfigRepository;

/**
 * Calcul du score GEI selon la formule de l'Annexe C.
 *
 * Formule : Score = (Fiabilité × Crédibilité) + Urgence + Impact + Exploitabilité
 *
 * Conversions (fixes — jamais configurables, c'est la logique de la matrice) :
 *   Fiabilité   : A=4, B=3, C=2, D=1   (via FiabiliteSource::score())
 *   Crédibilité : 1→4, 2→3, 3→2, 4→1  (inversée — 1 = plus crédible)
 *   Urgence     : Immédiat=3, 72h=2, Routine=1  (via AlertUrgence::score())
 *   Impact      : Élevé=3, Moyen=2, Faible=1    (via AlertImpact::score())
 *   Exploitabilité : Actionnable=3, À compléter=2, Archivage=1 (via AlertExploitabilite::score())
 *
 * Seuils de priorité — lus depuis la table rule_config (éditables sans redéploiement) :
 *   score_critique   : ≥ 18 → CRITIQUE   (défaut Annexe C)
 *   score_eleve_min  : ≥ 14 → ÉLEVÉ      (défaut Annexe C)
 *   score_modere_min : ≥ 10 → MODÉRÉ     (défaut Annexe C)
 *   en dessous       : FAIBLE
 *
 * B.3 Vérification : les seuils sont lus depuis rule_config, jamais codés en dur dans la formule.
 */
class ScoreCalculatorService
{
    /** Seuils par défaut conformes à l'Annexe C — utilisés si rule_config est vide */
    private const DEFAULT_SEUIL_CRITIQUE   = 18;
    private const DEFAULT_SEUIL_ELEVE_MIN  = 14;
    private const DEFAULT_SEUIL_MODERE_MIN = 10;

    /** Cache local des seuils pour éviter N requêtes par flush */
    private ?array $seuilsCache = null;

    public function __construct(
        private readonly RuleConfigRepository $ruleConfigRepo,
    ) {}

    /**
     * Calcule et applique le score GEI sur l'alerte.
     * Retourne true si le score ou le niveau a changé.
     */
    public function calculate(Alert $alert): bool
    {
        if (!$alert->isScoreable()) {
            return false;
        }

        $score = $this->computeScore($alert);
        $niveau = $this->resolveNiveauPriorite($score);

        $changed = $alert->getScoreGei() !== $score
            || $alert->getNiveauPriorite() !== $niveau;

        $alert->setScoreGei($score);
        $alert->setNiveauPriorite($niveau);

        return $changed;
    }

    /**
     * Calcule le score brut sans modifier l'alerte.
     * Utile pour le calcul live JavaScript côté serveur (endpoint preview-score).
     */
    public function computeScore(Alert $alert): int
    {
        $fiabilite    = $alert->getFiabiliteSource()?->score() ?? 0;
        $credibilite  = $this->convertCredibilite($alert->getCredibiliteContenu() ?? 0);
        $urgence      = $alert->getUrgence()?->score() ?? 0;
        $impact       = $alert->getImpact()?->score() ?? 0;
        $exploitabilite = $alert->getExploitabilite()?->score() ?? 0;

        return ($fiabilite * $credibilite) + $urgence + $impact + $exploitabilite;
    }

    /**
     * Détermine le niveau de priorité à partir d'un score en lisant les seuils
     * depuis la table rule_config (éditables par le superadmin sans redéploiement).
     * Conforme à la Partie B.3 du prompt expert.
     */
    public function resolveNiveauPriorite(int $score): NiveauPriorite
    {
        $seuils = $this->getSeuils();

        return match(true) {
            $score >= $seuils['critique']   => NiveauPriorite::CRITIQUE,
            $score >= $seuils['eleve_min']  => NiveauPriorite::ELEVE,
            $score >= $seuils['modere_min'] => NiveauPriorite::MODERE,
            default                          => NiveauPriorite::FAIBLE,
        };
    }

    /**
     * Crée l'entrée d'historique de qualification (immutable, jamais écrasée).
     * Snapshot complet de tous les critères au moment du calcul.
     */
    public function createHistoryEntry(Alert $alert, string $calculePar = 'system', ?int $oldScore = null, ?int $newScore = null): AlertQualificationHistory
    {
        $recordedScore = $newScore ?? $alert->getScoreGei() ?? 0;
        $entry = new AlertQualificationHistory();
        $entry->setAlert($alert);
        $entry->setScoreGei($recordedScore);
        $entry->setOldScore($oldScore);
        $entry->setNewScore($recordedScore);
        $entry->setNiveauPriorite($alert->getNiveauPriorite() ?? NiveauPriorite::FAIBLE);
        $entry->setCalculePar($calculePar);
        $entry->setCriteresSnapshot([
            'fiabilite'           => $alert->getFiabiliteSource()?->value,
            'fiabilite_score'     => $alert->getFiabiliteSource()?->score(),
            'credibilite_raw'     => $alert->getCredibiliteContenu(),
            'credibilite_score'   => $this->convertCredibilite($alert->getCredibiliteContenu() ?? 0),
            'urgence'             => $alert->getUrgence()?->value,
            'urgence_score'       => $alert->getUrgence()?->score(),
            'impact'              => $alert->getImpact()?->value,
            'impact_score'        => $alert->getImpact()?->score(),
            'exploitabilite'      => $alert->getExploitabilite()?->value,
            'exploitabilite_score' => $alert->getExploitabilite()?->score(),
            'score_final'         => $recordedScore,
            'niveau'              => $alert->getNiveauPriorite()?->value,
            'seuils_appliques'    => $this->getSeuils(),
        ]);

        return $entry;
    }

    /**
     * Calcule le score à partir de valeurs brutes (pour API JS live preview).
     * Retourne le détail complet : score, niveau, icône, décomposition des points.
     */
    public function computeFromValues(
        string $fiabilite,
        int    $credibilite,
        string $urgence,
        string $impact,
        string $exploitabilite,
    ): array {
        $fiabiliteEnum    = FiabiliteSource::tryFrom($fiabilite) ?? FiabiliteSource::C;
        $urgenceEnum      = AlertUrgence::tryFrom($urgence) ?? AlertUrgence::ROUTINE;
        $impactEnum       = AlertImpact::tryFrom($impact) ?? AlertImpact::MOYEN;
        $exploitabileEnum = AlertExploitabilite::tryFrom($exploitabilite) ?? AlertExploitabilite::A_COMPLETER;

        $fScore = $fiabiliteEnum->score();
        $cScore = $this->convertCredibilite($credibilite);
        $uScore = $urgenceEnum->score();
        $iScore = $impactEnum->score();
        $eScore = $exploitabileEnum->score();

        $total  = ($fScore * $cScore) + $uScore + $iScore + $eScore;
        $niveau = $this->resolveNiveauPriorite($total);

        return [
            'score'        => $total,
            'niveau'       => $niveau->value,
            'niveau_label' => $niveau->label(),
            'niveau_icon'  => $niveau->icon(),
            'seuils'       => $this->getSeuils(),
            'detail'       => [
                'fiabilite'              => $fScore,
                'credibilite'            => $cScore,
                'fiabilite_x_credibilite' => $fScore * $cScore,
                'urgence'                => $uScore,
                'impact'                 => $iScore,
                'exploitabilite'         => $eScore,
            ],
        ];
    }

    /**
     * Conversion crédibilité (Annexe C — inversée) :
     *   1 (plus crédible)   → score 4
     *   2 (probable)        → score 3
     *   3 (douteuse)        → score 2
     *   4 (non vérifiable)  → score 1
     *
     * Test obligatoire B.2 : Crédibilité=1 → contribue 4 points (pas 1).
     */
    public function convertCredibilite(int $raw): int
    {
        return match($raw) {
            1 => 4,
            2 => 3,
            3 => 2,
            4 => 1,
            default => 0,
        };
    }

    /**
     * Charge les seuils depuis rule_config avec fallback sur les valeurs Annexe C.
     * Résultat mis en cache pour la durée de la requête HTTP.
     */
    private function getSeuils(): array
    {
        if (null !== $this->seuilsCache) {
            return $this->seuilsCache;
        }

        $this->seuilsCache = [
            'critique'   => $this->ruleConfigRepo->getInt('score_critique',   self::DEFAULT_SEUIL_CRITIQUE),
            'eleve_min'  => $this->ruleConfigRepo->getInt('score_eleve_min',  self::DEFAULT_SEUIL_ELEVE_MIN),
            'modere_min' => $this->ruleConfigRepo->getInt('score_modere_min', self::DEFAULT_SEUIL_MODERE_MIN),
        ];

        return $this->seuilsCache;
    }
}
