<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Cas d'urgence 72h — créé automatiquement si score >= 18 (EscalationRuleEngine).
 * Peut aussi être activé manuellement par un PFT avec justification (Annexe E Phase 0).
 */
#[ORM\Entity]
#[ORM\Table(name: 'urgence_72h_case')]
class Urgence72hCase
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'urgence72hCase', targetEntity: Alert::class)]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private ?Alert $alert = null;

    /** T0 — horodatage immuable d'activation */
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $dateActivation;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $pftResponsable = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $saholtyValideBy = null;

    /** active, cloturee */
    #[ORM\Column(type: 'string', length: 20)]
    private string $statutCase = 'active';

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTime $dateCloture = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $delaiReelHeures = null;

    /** true = activé manuellement par PFT (pas par l'engine automatique) */
    #[ORM\Column(type: 'boolean')]
    private bool $activationManuelle = false;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $justificationManuelle = null;

    /** Enseignements capitalisés à la clôture */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $enseignements = null;

    #[ORM\OneToMany(mappedBy: 'urgenceCase', targetEntity: Urgence72hPhase::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['phase' => 'ASC'])]
    private Collection $phases;

    public function __construct()
    {
        $this->phases = new ArrayCollection();
        $this->dateActivation = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getAlert(): ?Alert { return $this->alert; }
    public function setAlert(?Alert $alert): static { $this->alert = $alert; return $this; }

    public function getDateActivation(): \DateTimeImmutable { return $this->dateActivation; }
    public function setDateActivation(\DateTimeImmutable $dt): static { $this->dateActivation = $dt; return $this; }

    public function getPftResponsable(): ?User { return $this->pftResponsable; }
    public function setPftResponsable(?User $user): static { $this->pftResponsable = $user; return $this; }

    public function getSaholtyValideBy(): ?User { return $this->saholtyValideBy; }
    public function setSaholtyValideBy(?User $user): static { $this->saholtyValideBy = $user; return $this; }

    public function getStatutCase(): string { return $this->statutCase; }
    public function setStatutCase(string $statutCase): static { $this->statutCase = $statutCase; return $this; }

    public function getDateCloture(): ?\DateTime { return $this->dateCloture; }
    public function setDateCloture(?\DateTime $dateCloture): static { $this->dateCloture = $dateCloture; return $this; }

    public function getDelaiReelHeures(): ?float { return $this->delaiReelHeures; }
    public function setDelaiReelHeures(?float $h): static { $this->delaiReelHeures = $h; return $this; }

    public function isActivationManuelle(): bool { return $this->activationManuelle; }
    public function setActivationManuelle(bool $b): static { $this->activationManuelle = $b; return $this; }

    public function getJustificationManuelle(): ?string { return $this->justificationManuelle; }
    public function setJustificationManuelle(?string $j): static { $this->justificationManuelle = $j; return $this; }

    public function getEnseignements(): ?string { return $this->enseignements; }
    public function setEnseignements(?string $e): static { $this->enseignements = $e; return $this; }

    public function getPhases(): Collection { return $this->phases; }

    public function addPhase(Urgence72hPhase $phase): static
    {
        if (!$this->phases->contains($phase)) {
            $this->phases->add($phase);
            $phase->setUrgenceCase($this);
        }
        return $this;
    }

    public function isActive(): bool { return $this->statutCase === 'active'; }

    /** Calcule les heures écoulées depuis T0 */
    public function getHeuresEcoulees(): float
    {
        $now = new \DateTime();
        $diff = $now->getTimestamp() - $this->dateActivation->getTimestamp();
        return round($diff / 3600, 1);
    }

    /** Calcule les heures restantes avant T+72h */
    public function getHeuresRestantes(): float
    {
        return max(0, 72 - $this->getHeuresEcoulees());
    }

    /** Couleur SLA : vert (>24h), orange (6-24h), rouge (<6h) */
    public function getSlaColorClass(): string
    {
        $restantes = $this->getHeuresRestantes();
        return match(true) {
            $restantes > 24 => 'sla-green',
            $restantes > 6 => 'sla-orange',
            default => 'sla-red',
        };
    }
}
