<?php

namespace App\Entity;

use App\Enum\PhaseUrgence;
use Doctrine\ORM\Mapping as ORM;

/**
 * Phase individuelle du cas d'urgence 72h (Annexe E).
 * 5 phases : détection → qualification → validation → coordination → suivi
 */
#[ORM\Entity]
#[ORM\Table(name: 'urgence_72h_phase')]
class Urgence72hPhase
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Urgence72hCase::class, inversedBy: 'phases')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Urgence72hCase $urgenceCase = null;

    #[ORM\Column(type: 'string', enumType: PhaseUrgence::class)]
    private PhaseUrgence $phase;

    /** Heure limite SLA absolue (T0 + X heures) */
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $slaHeureLimite;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $dateDebut = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $dateFin = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $responsable = null;

    /** Calculé par SlaMonitorService */
    #[ORM\Column(type: 'boolean')]
    private bool $enRetard = false;

    /** Rappel automatique envoyé */
    #[ORM\Column(type: 'boolean')]
    private bool $rappelEnvoye = false;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $commentaire = null;

    public function getId(): ?int { return $this->id; }

    public function getUrgenceCase(): ?Urgence72hCase { return $this->urgenceCase; }
    public function setUrgenceCase(?Urgence72hCase $urgenceCase): static { $this->urgenceCase = $urgenceCase; return $this; }

    public function getPhase(): PhaseUrgence { return $this->phase; }
    public function setPhase(PhaseUrgence $phase): static { $this->phase = $phase; return $this; }

    public function getSlaHeureLimite(): \DateTimeImmutable { return $this->slaHeureLimite; }
    public function setSlaHeureLimite(\DateTimeImmutable $slaHeureLimite): static { $this->slaHeureLimite = $slaHeureLimite; return $this; }

    public function getDateDebut(): ?\DateTimeImmutable { return $this->dateDebut; }
    public function setDateDebut(?\DateTimeImmutable $dateDebut): static { $this->dateDebut = $dateDebut; return $this; }

    public function getDateFin(): ?\DateTimeImmutable { return $this->dateFin; }
    public function setDateFin(?\DateTimeImmutable $dateFin): static { $this->dateFin = $dateFin; return $this; }

    public function getResponsable(): ?User { return $this->responsable; }
    public function setResponsable(?User $responsable): static { $this->responsable = $responsable; return $this; }

    public function isEnRetard(): bool { return $this->enRetard; }
    public function setEnRetard(bool $enRetard): static { $this->enRetard = $enRetard; return $this; }

    public function isRappelEnvoye(): bool { return $this->rappelEnvoye; }
    public function setRappelEnvoye(bool $rappelEnvoye): static { $this->rappelEnvoye = $rappelEnvoye; return $this; }

    public function getCommentaire(): ?string { return $this->commentaire; }
    public function setCommentaire(?string $commentaire): static { $this->commentaire = $commentaire; return $this; }

    public function isTerminee(): bool { return null !== $this->dateFin; }

    public function getMinutesRestantes(): int
    {
        $now = new \DateTimeImmutable();
        $diff = $this->slaHeureLimite->getTimestamp() - $now->getTimestamp();
        return max(0, (int)($diff / 60));
    }
}
