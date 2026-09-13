<?php

namespace App\Service;

use App\Entity\Alert;
use App\Entity\Urgence72hCase;
use App\Entity\Urgence72hPhase;
use App\Enum\AlertUrgence;
use App\Enum\AlertStatut;
use App\Enum\AlertImpact;
use App\Enum\AlertExploitabilite;
use App\Enum\FiabiliteSource;
use App\Enum\PhaseUrgence;
use App\Message\AlertEscalationMessage;
use App\Repository\RuleConfigRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Moteur de règles d'escalade (§3.2 spec).
 * 7 règles évaluées séquentiellement après chaque calcul de score.
 * Les seuils sont lus depuis la table rule_config (configurables sans code).
 */
class EscalationRuleEngine
{
    private array $config = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly RuleConfigRepository $ruleConfigRepo,
    ) {}

    /**
     * Point d'entrée — évalue toutes les règles sur une alerte.
     * Appelé par AlertScoreSubscriber après chaque calcul de score.
     */
    public function evaluate(Alert $alert): void
    {
        $this->loadConfig();

        $this->regle1_CritiqueCree72h($alert);
        $this->regle2_TransmissionPrioritaire($alert);
        $this->regle3_TacheAnalyseRapide($alert);
        $this->regle4_MultiPaysComiteAit($alert);
        $this->regle5_BlockageValidationSaholty($alert);
        $this->regle6_DemandeCorroboration($alert);
        $this->regle7_SurveillanceRenforcee($alert);
    }

    /**
     * Règle 1 : une procédure 72h n'est créée qu'après validation Manager,
     * et uniquement pour une urgence explicitement classée 72h.
     */
    private function regle1_CritiqueCree72h(Alert $alert): void
    {
        if ($alert->isEligibleForUrgence72h() && null === $alert->getUrgence72hCase()) {
            $case = new Urgence72hCase();
            $case->setAlert($alert);
            $case->setDateActivation(new \DateTimeImmutable());

            // Création des 3 phases Urgence 72h (F.1 & F.2)
            foreach (PhaseUrgence::cases() as $phaseEnum) {
                $phase = new Urgence72hPhase();
                $phase->setPhase($phaseEnum);
                $slaLimite = $case->getDateActivation()->modify('+' . $phaseEnum->slaHeures() . ' hours');
                $phase->setSlaHeureLimite($slaLimite);

                if ($phaseEnum === PhaseUrgence::DETECTION) {
                    // Détection (T0) automatiquement complétée dès sa création
                    $phase->setDateDebut($case->getDateActivation());
                    $phase->setDateFin($case->getDateActivation());
                } elseif ($phaseEnum === PhaseUrgence::COORDINATION) {
                    $phase->setDateDebut($case->getDateActivation());
                }

                $case->addPhase($phase);
            }

            $alert->setUrgence72hCase($case);
            $this->em->persist($case);

            $this->bus->dispatch(new AlertEscalationMessage(
                alertId: $alert->getId() ?? 0,
                type: 'urgence_72h_created',
                details: ['score' => $alert->getScoreGei()]
            ));
        }
    }

    /**
     * Règle 2 : Urgence = Immédiat ET Impact = Élevé → transmission_prioritaire + notification SAHOLTY
     */
    private function regle2_TransmissionPrioritaire(Alert $alert): void
    {
        $isPrioritaire = $alert->getUrgence() === AlertUrgence::IMMEDIAT
            && $alert->getImpact() === AlertImpact::ELEVE;

        $alert->setTransmissionPrioritaire($isPrioritaire);

        if ($isPrioritaire) {
            $this->bus->dispatch(new AlertEscalationMessage(
                alertId: $alert->getId() ?? 0,
                type: 'transmission_prioritaire',
                details: []
            ));
        }
    }

    /**
     * Règle 3 : Exploitabilité = Actionnable ET Crédibilité ≥ 2 (code ≤ 2 dans logique inversée)
     * → Tâche "analyse rapide" assignée au PFT, échéance +6h
     */
    private function regle3_TacheAnalyseRapide(Alert $alert): void
    {
        $credibilite = $alert->getCredibiliteContenu() ?? 5;
        $condition = $alert->getExploitabilite() === AlertExploitabilite::ACTIONNABLE
            && $credibilite <= 2;

        if ($condition) {
            $this->bus->dispatch(new AlertEscalationMessage(
                alertId: $alert->getId() ?? 0,
                type: 'tache_analyse_rapide',
                details: ['echeance_heures' => 6]
            ));
        }
    }

    /**
     * Règle 4 : Alerte liée à ≥ 2 corridors (détection via texte port_corridor)
     * → Notification automatique COMITE_AIT
     */
    private function regle4_MultiPaysComiteAit(Alert $alert): void
    {
        $corridor = $alert->getPortCorridor() ?? '';
        // Heuristique : présence d'un séparateur (/, –, ,) dans le corridor = multi-corridor
        $isMulti = preg_match('/[\/\-–,]/', $corridor) === 1;

        if ($isMulti) {
            $this->bus->dispatch(new AlertEscalationMessage(
                alertId: $alert->getId() ?? 0,
                type: 'multi_corridor_comite_ait',
                details: ['corridor' => $corridor]
            ));
        }
    }

    /**
     * Règle 5 : Score 14-17 → statut passe en a_valider_saholty avant transmission
     */
    private function regle5_BlockageValidationSaholty(Alert $alert): void
    {
        $score = $alert->getScoreGei() ?? 0;
        $seuilEleve = $this->getConfig('score_eleve_min', 14);
        $seuilCritique = $this->getConfig('score_critique', 18);

        if ($score >= $seuilEleve && $score < $seuilCritique) {
            // Le blocage est géré par le workflow - on notifie seulement
            $this->bus->dispatch(new AlertEscalationMessage(
                alertId: $alert->getId() ?? 0,
                type: 'validation_saholty_requise',
                details: ['score' => $score]
            ));
        }
    }

    /**
     * Règle 6 : Fiabilité C ou D ET Impact = Élevé → tâche "demande de corroboration"
     */
    private function regle6_DemandeCorroboration(Alert $alert): void
    {
        $fiabilite = $alert->getFiabiliteSource();
        $condition = in_array($fiabilite, [FiabiliteSource::C, FiabiliteSource::D])
            && $alert->getImpact() === AlertImpact::ELEVE;

        if ($condition) {
            $this->bus->dispatch(new AlertEscalationMessage(
                alertId: $alert->getId() ?? 0,
                type: 'demande_corroboration',
                details: ['fiabilite' => $fiabilite?->value]
            ));
        }
    }

    /**
     * Règle 7 : Crédibilité = 3 ET Urgence = Immédiat/72h → surveillance_renforcee
     */
    private function regle7_SurveillanceRenforcee(Alert $alert): void
    {
        $credibilite = $alert->getCredibiliteContenu() ?? 0;
        $urgence = $alert->getUrgence();
        $condition = $credibilite === 3
            && in_array($urgence, [AlertUrgence::IMMEDIAT, AlertUrgence::SOIXANTE_DOUZE_H]);

        $alert->setSurveillanceRenforcee($condition);
    }

    private function getConfig(string $cle, int $defaut): int
    {
        return isset($this->config[$cle]) ? (int)$this->config[$cle] : $defaut;
    }

    private function loadConfig(): void
    {
        if (!empty($this->config)) {
            return;
        }

        $this->config = $this->ruleConfigRepo->findAllAsMap();
    }
}
