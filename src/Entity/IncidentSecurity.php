<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Incidents de sécurité (Annexe D §10).
 * Accessible à tout utilisateur connecté pour signalement.
 * Jamais visible par EMETTEUR_TERRAIN autre que le déclarant.
 */
#[ORM\Entity]
#[ORM\Table(name: 'incident_security')]
#[ORM\HasLifecycleCallbacks]
class IncidentSecurity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Alert::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Alert $alert = null;

    /** violation_donnees, acces_non_autorise, fuite_information, perte_materiel, autre */
    #[ORM\Column(type: 'string', length: 50)]
    private string $typeIncident;

    /** faible, modere, eleve, critique */
    #[ORM\Column(type: 'string', length: 20)]
    private string $gravite = 'modere';

    #[ORM\Column(type: 'text')]
    private string $description;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $signaleBy = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $signaleLe;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $mesuresCorrectives = null;

    /** en_cours, traite, clos */
    #[ORM\Column(type: 'string', length: 20)]
    private string $statut = 'en_cours';

    /** Escalade au Comité AIT requise */
    #[ORM\Column(type: 'boolean')]
    private bool $escaladeComiteAit = false;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->signaleLe = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getAlert(): ?Alert { return $this->alert; }
    public function setAlert(?Alert $alert): static { $this->alert = $alert; return $this; }

    public function getTypeIncident(): string { return $this->typeIncident; }
    public function setTypeIncident(string $typeIncident): static { $this->typeIncident = $typeIncident; return $this; }

    public function getGravite(): string { return $this->gravite; }
    public function setGravite(string $gravite): static { $this->gravite = $gravite; return $this; }

    public function getDescription(): string { return $this->description; }
    public function setDescription(string $description): static { $this->description = $description; return $this; }

    public function getSignaleBy(): ?User { return $this->signaleBy; }
    public function setSignaleBy(?User $signaleBy): static { $this->signaleBy = $signaleBy; return $this; }

    public function getSignaleLe(): \DateTimeImmutable { return $this->signaleLe; }

    public function getMesuresCorrectives(): ?string { return $this->mesuresCorrectives; }
    public function setMesuresCorrectives(?string $mesuresCorrectives): static { $this->mesuresCorrectives = $mesuresCorrectives; return $this; }

    public function getStatut(): string { return $this->statut; }
    public function setStatut(string $statut): static { $this->statut = $statut; return $this; }

    public function isEscaladeComiteAit(): bool { return $this->escaladeComiteAit; }
    public function setEscaladeComiteAit(bool $escaladeComiteAit): static { $this->escaladeComiteAit = $escaladeComiteAit; return $this; }
}
