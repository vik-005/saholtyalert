<?php

namespace App\Entity;

use App\Enum\AlertStatut;
use Doctrine\ORM\Mapping as ORM;

/**
 * Audit de chaque changement de statut d'une alerte (§3.3 spec).
 * Chaque transition workflow est enregistrée ici automatiquement.
 */
#[ORM\Entity]
#[ORM\Table(name: 'alert_status_history')]
class AlertStatusHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Alert::class, inversedBy: 'statusHistories')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Alert $alert = null;

    #[ORM\Column(type: 'string', enumType: AlertStatut::class)]
    private AlertStatut $ancienStatut;

    #[ORM\Column(type: 'string', enumType: AlertStatut::class)]
    private AlertStatut $nouveauStatut;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $changedBy = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $changedAt;

    /** Justification obligatoire pour passage à 'clos' */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $justification = null;

    /** Nom de la transition Workflow déclenchée */
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $transitionName = null;

    public function __construct()
    {
        $this->changedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getAlert(): ?Alert { return $this->alert; }
    public function setAlert(?Alert $alert): static { $this->alert = $alert; return $this; }

    public function getAncienStatut(): AlertStatut { return $this->ancienStatut; }
    public function setAncienStatut(AlertStatut $ancienStatut): static { $this->ancienStatut = $ancienStatut; return $this; }

    public function getNouveauStatut(): AlertStatut { return $this->nouveauStatut; }
    public function setNouveauStatut(AlertStatut $nouveauStatut): static { $this->nouveauStatut = $nouveauStatut; return $this; }

    public function getChangedBy(): ?User { return $this->changedBy; }
    public function setChangedBy(?User $changedBy): static { $this->changedBy = $changedBy; return $this; }

    public function getChangedAt(): \DateTimeImmutable { return $this->changedAt; }

    public function getJustification(): ?string { return $this->justification; }
    public function setJustification(?string $justification): static { $this->justification = $justification; return $this; }

    public function getTransitionName(): ?string { return $this->transitionName; }
    public function setTransitionName(?string $transitionName): static { $this->transitionName = $transitionName; return $this; }
}
