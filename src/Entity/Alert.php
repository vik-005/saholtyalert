<?php

namespace App\Entity;

use App\Enum\AlertCategorie;
use App\Enum\AlertExploitabilite;
use App\Enum\AlertImpact;
use App\Enum\AlertStatut;
use App\Enum\AlertUrgence;
use App\Enum\AnonymisationNiveau;
use App\Enum\FiabiliteSource;
use App\Enum\NiveauPriorite;
use App\Enum\Recommandation;
use App\Enum\Sensibilite;
use App\Enum\TransmissionStatut;
use App\Enum\TypeAlerte;
use App\Enum\TypeSource;
use App\Repository\AlertRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entité centrale — Fiche d'alerte GEI (Annexe A + Registre).
 * Le score GEI et le niveau de priorité sont TOUJOURS calculés automatiquement
 * par ScoreCalculatorService (via AlertScoreSubscriber::onFlush).
 * Ils ne sont JAMAIS saisis manuellement (garde-fou §3).
 */
#[ORM\Entity(repositoryClass: AlertRepository::class)]
#[ORM\Table(name: 'alert')]
#[ORM\HasLifecycleCallbacks]
class Alert
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /** Format : GEI-{ISO3}-{ANNEE}-{SEQ:003} — généré automatiquement */
    #[ORM\Column(type: 'string', length: 30, unique: true, nullable: true)]
    private ?string $codeGei = null;

    #[ORM\Column(type: 'date')]
    private \DateTimeInterface $dateCreation;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'alertesEmises')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $emetteur = null;

    #[ORM\ManyToOne(targetEntity: Market::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Market $market = null;

    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $portCorridor = null;

    #[ORM\Column(type: 'string', enumType: AlertCategorie::class)]
    private ?AlertCategorie $categorie = null;

    /**
     * Résumé exécutif — max 500 caractères, séparé des faits et hypothèses (garde-fou §3.1).
     */
    #[ORM\Column(type: 'text')]
    #[Assert\NotBlank(message: 'Le résumé exécutif est obligatoire.')]
    #[Assert\Length(max: 500, maxMessage: 'Le résumé ne peut pas dépasser {{ limit }} caractères.')]
    private ?string $resumeExecutif = null;

    #[ORM\Column(type: 'string', enumType: TypeSource::class, nullable: true)]
    private ?TypeSource $typeSource = null;

    #[ORM\Column(type: 'string', enumType: TypeAlerte::class, nullable: true)]
    private ?TypeAlerte $typeAlerte = null;

    /** Anonymisation ÉLEVÉ par défaut — non désactivable sans rôle SAHOLTY (§3.5) */
    #[ORM\Column(type: 'string', enumType: AnonymisationNiveau::class)]
    private AnonymisationNiveau $anonymisation = AnonymisationNiveau::ELEVE;

    /**
     * Historique de la source — section 3 de l'Annexe A, rempli par l'AGENT.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $historiqueSource = null;

    /**
     * Texte libre de l'émetteur tel qu'importé depuis le registre Excel.
     * Préserve "Point focal Douanes Bénin" lors d'un import
     * quand aucun User correspondant n'existe en base.
     */
    #[ORM\Column(type: 'string', length: 200, nullable: true)]
    private ?string $emetteurTexte = null;

    /**
     * Type de pièce(s) disponible(s) — ex. "manifeste", "photos", "déclaration".
     * Colonne R du registre : "Oui – manifeste" → stocké ici comme "manifeste".
     */
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $piecesType = null;

    #[ORM\Column(type: 'string', enumType: FiabiliteSource::class, nullable: true)]
    private ?FiabiliteSource $fiabiliteSource = null;

    /** Crédibilité : 1 (plus crédible) → 4 (non vérifiable) */
    #[ORM\Column(type: 'integer', nullable: true)]
    #[Assert\Range(min: 1, max: 4)]
    private ?int $credibiliteContenu = null;

    #[ORM\Column(type: 'string', enumType: AlertUrgence::class, nullable: true)]
    private ?AlertUrgence $urgence = null;

    #[ORM\Column(type: 'string', enumType: AlertImpact::class, nullable: true)]
    private ?AlertImpact $impact = null;

    #[ORM\Column(type: 'string', enumType: AlertExploitabilite::class, nullable: true)]
    private ?AlertExploitabilite $exploitabilite = null;

    /** Statut piloté par Symfony Workflow (§3.3) */
    #[ORM\Column(type: 'string', enumType: AlertStatut::class)]
    private AlertStatut $statut = AlertStatut::NOUVEAU;

    #[ORM\Column(type: 'string', enumType: TransmissionStatut::class)]
    private TransmissionStatut $transmission = TransmissionStatut::NON;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $actionsEnCours = null;

    #[ORM\Column(type: 'boolean')]
    private bool $piecesDisponibles = false;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $referenceDocumentaire = null;

    #[ORM\Column(type: 'string', enumType: Sensibilite::class)]
    private Sensibilite $sensibilite = Sensibilite::RESTREINTE;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'alertesSuivies')]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $responsableSuivi = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $commentaires = null;

    /**
     * Éléments factuels — JAMAIS mélangés avec les hypothèses analytiques (garde-fou Annexe C).
     * Colonne distincte en base, validation Symfony au niveau Form + Entity.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $elementsFactuels = null;

    /**
     * Hypothèses analytiques — JAMAIS fusionnées avec les faits (garde-fou Annexe C).
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $hypothesesAnalytiques = null;

    #[ORM\Column(type: 'string', enumType: Recommandation::class, nullable: true)]
    private ?Recommandation $recommandation = null;

    /**
     * Score GEI calculé automatiquement par ScoreCalculatorService.
     * JAMAIS modifiable manuellement.
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $scoreGei = null;

    /**
     * Niveau de priorité dérivé du score — calculé automatiquement.
     * JAMAIS modifiable manuellement directement (sauf via la surcharge manager).
     */
    #[ORM\Column(type: 'string', enumType: NiveauPriorite::class, nullable: true)]
    private ?NiveauPriorite $niveauPriorite = null;

    /** Surcharge manuelle du score par un manager */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $scoreSurcharge = null;

    /** Surcharge manuelle du niveau de priorité par un manager */
    #[ORM\Column(type: 'string', enumType: NiveauPriorite::class, nullable: true)]
    private ?NiveauPriorite $niveauPrioriteSurcharge = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $surchargePar = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $justificationSurcharge = null;

    /** Origine de l'alerte : saisie_agent ou import_excel (Spec B.4) */
    #[ORM\Column(type: 'string', length: 30)]
    private string $origine = 'saisie_agent';

    /** ID de lot d'importation pour traçabilité et annulation (Spec B.4) */
    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $importBatchId = null;

    /** Flag déclenché par EscalationRuleEngine */
    #[ORM\Column(type: 'boolean')]
    private bool $transmissionPrioritaire = false;

    /** Flag déclenché par EscalationRuleEngine */
    #[ORM\Column(type: 'boolean')]
    private bool $surveillanceRenforcee = false;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime')]
    private \DateTime $updatedAt;

    #[ORM\OneToMany(mappedBy: 'alert', targetEntity: AlertActor::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $acteurs;

    #[ORM\OneToMany(mappedBy: 'alert', targetEntity: AlertAttachment::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $pieceJointes;

    #[ORM\OneToMany(mappedBy: 'alert', targetEntity: AlertQualificationHistory::class, cascade: ['persist'], orphanRemoval: false)]
    #[ORM\OrderBy(['calculeLe' => 'DESC'])]
    private Collection $qualificationHistories;

    #[ORM\OneToMany(mappedBy: 'alert', targetEntity: AlertStatusHistory::class, cascade: ['persist'])]
    #[ORM\OrderBy(['changedAt' => 'DESC'])]
    private Collection $statusHistories;

    #[ORM\OneToMany(mappedBy: 'alert', targetEntity: AlertTransmission::class, cascade: ['persist'])]
    private Collection $transmissions;

    #[ORM\OneToOne(mappedBy: 'alert', targetEntity: Urgence72hCase::class, cascade: ['persist'])]
    private ?Urgence72hCase $urgence72hCase = null;

    public function __construct()
    {
        $this->acteurs = new ArrayCollection();
        $this->pieceJointes = new ArrayCollection();
        $this->qualificationHistories = new ArrayCollection();
        $this->statusHistories = new ArrayCollection();
        $this->transmissions = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTime();
        $this->dateCreation = new \DateTime();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTime();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCodeGei(): ?string
    {
        return $this->codeGei;
    }

    public function setCodeGei(?string $codeGei): static
    {
        $this->codeGei = $codeGei;
        return $this;
    }

    public function getDateCreation(): \DateTimeInterface
    {
        return $this->dateCreation;
    }

    public function setDateCreation(\DateTimeInterface $dateCreation): static
    {
        $this->dateCreation = $dateCreation;
        return $this;
    }

    public function getEmetteur(): ?User
    {
        return $this->emetteur;
    }

    public function setEmetteur(?User $emetteur): static
    {
        $this->emetteur = $emetteur;
        return $this;
    }

    public function getMarket(): ?Market
    {
        return $this->market;
    }

    public function setMarket(?Market $market): static
    {
        $this->market = $market;
        return $this;
    }

    public function getPortCorridor(): ?string
    {
        return $this->portCorridor;
    }

    public function setPortCorridor(?string $portCorridor): static
    {
        $this->portCorridor = $portCorridor;
        return $this;
    }

    public function getCategorie(): ?AlertCategorie
    {
        return $this->categorie;
    }

    public function setCategorie(?AlertCategorie $categorie): static
    {
        $this->categorie = $categorie;
        return $this;
    }

    public function getResumeExecutif(): ?string
    {
        return $this->resumeExecutif;
    }

    public function setResumeExecutif(?string $resumeExecutif): static
    {
        $this->resumeExecutif = $resumeExecutif;
        return $this;
    }

    public function getTypeSource(): ?TypeSource
    {
        return $this->typeSource;
    }

    public function setTypeSource(?TypeSource $typeSource): static
    {
        $this->typeSource = $typeSource;
        return $this;
    }

    public function getTypeAlerte(): ?TypeAlerte
    {
        return $this->typeAlerte;
    }

    public function setTypeAlerte(?TypeAlerte $typeAlerte): static
    {
        $this->typeAlerte = $typeAlerte;
        return $this;
    }

    public function getAnonymisation(): AnonymisationNiveau
    {
        return $this->anonymisation;
    }

    public function setAnonymisation(AnonymisationNiveau $anonymisation): static
    {
        $this->anonymisation = $anonymisation;
        return $this;
    }

    public function getHistoriqueSource(): ?string
    {
        return $this->historiqueSource;
    }

    public function setHistoriqueSource(?string $historiqueSource): static
    {
        $this->historiqueSource = $historiqueSource;
        return $this;
    }

    public function getEmetteurTexte(): ?string
    {
        return $this->emetteurTexte;
    }

    public function setEmetteurTexte(?string $emetteurTexte): static
    {
        $this->emetteurTexte = $emetteurTexte;
        return $this;
    }

    public function getPiecesType(): ?string
    {
        return $this->piecesType;
    }

    public function setPiecesType(?string $piecesType): static
    {
        $this->piecesType = $piecesType;
        return $this;
    }

    public function getFiabiliteSource(): ?FiabiliteSource
    {
        return $this->fiabiliteSource;
    }

    public function setFiabiliteSource(?FiabiliteSource $fiabiliteSource): static
    {
        $this->fiabiliteSource = $fiabiliteSource;
        return $this;
    }

    public function getCredibiliteContenu(): ?int
    {
        return $this->credibiliteContenu;
    }

    public function setCredibiliteContenu(?int $credibiliteContenu): static
    {
        $this->credibiliteContenu = $credibiliteContenu;
        return $this;
    }

    public function getUrgence(): ?AlertUrgence
    {
        return $this->urgence;
    }

    public function setUrgence(?AlertUrgence $urgence): static
    {
        $this->urgence = $urgence;
        return $this;
    }

    public function getImpact(): ?AlertImpact
    {
        return $this->impact;
    }

    public function setImpact(?AlertImpact $impact): static
    {
        $this->impact = $impact;
        return $this;
    }

    public function getExploitabilite(): ?AlertExploitabilite
    {
        return $this->exploitabilite;
    }

    public function setExploitabilite(?AlertExploitabilite $exploitabilite): static
    {
        $this->exploitabilite = $exploitabilite;
        return $this;
    }

    public function getStatut(): AlertStatut
    {
        return $this->statut;
    }

    public function setStatut(AlertStatut $statut): static
    {
        $this->statut = $statut;
        return $this;
    }

    public function getTransmission(): TransmissionStatut
    {
        return $this->transmission;
    }

    public function setTransmission(TransmissionStatut $transmission): static
    {
        $this->transmission = $transmission;
        return $this;
    }

    public function getActionsEnCours(): ?string
    {
        return $this->actionsEnCours;
    }

    public function setActionsEnCours(?string $actionsEnCours): static
    {
        $this->actionsEnCours = $actionsEnCours;
        return $this;
    }

    public function isPiecesDisponibles(): bool
    {
        return $this->piecesDisponibles;
    }

    public function setPiecesDisponibles(bool $piecesDisponibles): static
    {
        $this->piecesDisponibles = $piecesDisponibles;
        return $this;
    }

    public function getReferenceDocumentaire(): ?string
    {
        return $this->referenceDocumentaire;
    }

    public function setReferenceDocumentaire(?string $referenceDocumentaire): static
    {
        $this->referenceDocumentaire = $referenceDocumentaire;
        return $this;
    }

    public function getSensibilite(): Sensibilite
    {
        return $this->sensibilite;
    }

    public function setSensibilite(Sensibilite $sensibilite): static
    {
        $this->sensibilite = $sensibilite;
        return $this;
    }

    public function getResponsableSuivi(): ?User
    {
        return $this->responsableSuivi;
    }

    public function setResponsableSuivi(?User $responsableSuivi): static
    {
        $this->responsableSuivi = $responsableSuivi;
        return $this;
    }

    public function getCommentaires(): ?string
    {
        return $this->commentaires;
    }

    public function setCommentaires(?string $commentaires): static
    {
        $this->commentaires = $commentaires;
        return $this;
    }

    public function getElementsFactuels(): ?string
    {
        return $this->elementsFactuels;
    }

    public function setElementsFactuels(?string $elementsFactuels): static
    {
        $this->elementsFactuels = $elementsFactuels;
        return $this;
    }

    public function getHypothesesAnalytiques(): ?string
    {
        return $this->hypothesesAnalytiques;
    }

    public function setHypothesesAnalytiques(?string $hypothesesAnalytiques): static
    {
        $this->hypothesesAnalytiques = $hypothesesAnalytiques;
        return $this;
    }

    public function getRecommandation(): ?Recommandation
    {
        return $this->recommandation;
    }

    public function setRecommandation(?Recommandation $recommandation): static
    {
        $this->recommandation = $recommandation;
        return $this;
    }

    public function getScoreGei(): ?int
    {
        return $this->scoreGei;
    }

    public function setScoreGei(?int $scoreGei): static
    {
        $this->scoreGei = $scoreGei;
        return $this;
    }

    public function getNiveauPriorite(): ?NiveauPriorite
    {
        return $this->niveauPriorite;
    }

    public function setNiveauPriorite(?NiveauPriorite $niveauPriorite): static
    {
        $this->niveauPriorite = $niveauPriorite;
        return $this;
    }

    public function getScoreSurcharge(): ?int
    {
        return $this->scoreSurcharge;
    }

    public function setScoreSurcharge(?int $scoreSurcharge): static
    {
        $this->scoreSurcharge = $scoreSurcharge;
        return $this;
    }

    public function getNiveauPrioriteSurcharge(): ?NiveauPriorite
    {
        return $this->niveauPrioriteSurcharge;
    }

    public function setNiveauPrioriteSurcharge(?NiveauPriorite $niveauPrioriteSurcharge): static
    {
        $this->niveauPrioriteSurcharge = $niveauPrioriteSurcharge;
        return $this;
    }

    public function getSurchargePar(): ?User
    {
        return $this->surchargePar;
    }

    public function setSurchargePar(?User $user): static
    {
        $this->surchargePar = $user;
        return $this;
    }

    public function getJustificationSurcharge(): ?string
    {
        return $this->justificationSurcharge;
    }

    public function setJustificationSurcharge(?string $justification): static
    {
        $this->justificationSurcharge = $justification;
        return $this;
    }

    public function getOrigine(): string
    {
        return $this->origine;
    }

    public function setOrigine(string $origine): static
    {
        $this->origine = $origine;
        return $this;
    }

    public function getImportBatchId(): ?string
    {
        return $this->importBatchId;
    }

    public function setImportBatchId(?string $batchId): static
    {
        $this->importBatchId = $batchId;
        return $this;
    }

    /** Retourne le score effectif (surcharge manuelle si présente, sinon calculé) */
    public function getEffectiveScore(): ?int
    {
        return $this->scoreSurcharge ?? $this->scoreGei;
    }

    /** Retourne le niveau de priorité effectif (surchargé si présent, sinon calculé) */
    public function getEffectiveNiveauPriorite(): ?NiveauPriorite
    {
        return $this->niveauPrioriteSurcharge ?? $this->niveauPriorite;
    }

    public function isTransmissionPrioritaire(): bool
    {
        return $this->transmissionPrioritaire;
    }

    public function setTransmissionPrioritaire(bool $transmissionPrioritaire): static
    {
        $this->transmissionPrioritaire = $transmissionPrioritaire;
        return $this;
    }

    public function isSurveillanceRenforcee(): bool
    {
        return $this->surveillanceRenforcee;
    }

    public function setSurveillanceRenforcee(bool $surveillanceRenforcee): static
    {
        $this->surveillanceRenforcee = $surveillanceRenforcee;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTime
    {
        return $this->updatedAt;
    }

    public function getActeurs(): Collection
    {
        return $this->acteurs;
    }

    public function addActeur(AlertActor $acteur): static
    {
        if (!$this->acteurs->contains($acteur)) {
            $this->acteurs->add($acteur);
            $acteur->setAlert($this);
        }
        return $this;
    }

    public function removeActeur(AlertActor $acteur): static
    {
        $this->acteurs->removeElement($acteur);
        return $this;
    }

    public function getPieceJointes(): Collection
    {
        return $this->pieceJointes;
    }

    public function addPieceJointe(AlertAttachment $attachment): static
    {
        if (!$this->pieceJointes->contains($attachment)) {
            $this->pieceJointes->add($attachment);
            $attachment->setAlert($this);
        }
        return $this;
    }

    public function removePieceJointe(AlertAttachment $attachment): static
    {
        $this->pieceJointes->removeElement($attachment);
        return $this;
    }

    public function getQualificationHistories(): Collection
    {
        return $this->qualificationHistories;
    }

    public function getStatusHistories(): Collection
    {
        return $this->statusHistories;
    }

    public function getTransmissions(): Collection
    {
        return $this->transmissions;
    }

    public function getUrgence72hCase(): ?Urgence72hCase
    {
        return $this->urgence72hCase;
    }

    public function setUrgence72hCase(?Urgence72hCase $urgence72hCase): static
    {
        $this->urgence72hCase = $urgence72hCase;
        return $this;
    }

    /** Indique si tous les critères de scoring sont renseignés */
    public function isScoreable(): bool
    {
        return null !== $this->fiabiliteSource
            && null !== $this->credibiliteContenu
            && null !== $this->urgence
            && null !== $this->impact
            && null !== $this->exploitabilite;
    }

    public function __toString(): string
    {
        return $this->codeGei ?? 'BROUILLON-' . $this->id;
    }
}
