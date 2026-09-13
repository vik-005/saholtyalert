<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Enregistrement des transmissions externes d'une alerte (Annexe A §7 + Annexe D §6).
 * Double validation requise : PFT ET SAHOLTY si score 14-17.
 */
#[ORM\Entity]
#[ORM\Table(name: 'alert_transmission')]
#[ORM\HasLifecycleCallbacks]
class AlertTransmission
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Alert::class, inversedBy: 'transmissions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Alert $alert = null;

    /** Douanes, Police, Autorités fiscales, Partenaires, Interpol, etc. */
    #[ORM\Column(type: 'string', length: 255)]
    private string $destinataire;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $transmisBy = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $transmisLe;

    /** Validation PFT (obligatoire) */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $validePftBy = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTime $validePftLe = null;

    /** Validation SAHOLTY (requise si score 14-17) */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $valideSaholtyBy = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTime $valideSaholtyLe = null;

    public const STATUT_EN_COURS = 'en_cours';
    public const STATUT_CLOTURE = 'cloture';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $noteTransmission = null;

    /** en_cours (En cours de résolution), cloture (Clôturé), ou pending/valide */
    #[ORM\Column(type: 'string', length: 30)]
    private string $statut = self::STATUT_EN_COURS;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTime $dateCloture = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->transmisLe = new \DateTimeImmutable();
        if ($this->statut === 'pending') {
            $this->statut = self::STATUT_EN_COURS;
        }
    }

    public function getId(): ?int { return $this->id; }

    public function getAlert(): ?Alert { return $this->alert; }
    public function setAlert(?Alert $alert): static { $this->alert = $alert; return $this; }

    public function getDestinataire(): string { return $this->destinataire; }
    public function setDestinataire(string $destinataire): static { $this->destinataire = $destinataire; return $this; }

    public function getTransmisBy(): ?User { return $this->transmisBy; }
    public function setTransmisBy(?User $transmisBy): static { $this->transmisBy = $transmisBy; return $this; }

    public function getTransmisLe(): \DateTimeImmutable { return $this->transmisLe; }

    public function getValidePftBy(): ?User { return $this->validePftBy; }
    public function setValidePftBy(?User $user): static { $this->validePftBy = $user; return $this; }

    public function getValidePftLe(): ?\DateTime { return $this->validePftLe; }
    public function setValidePftLe(?\DateTime $dt): static { $this->validePftLe = $dt; return $this; }

    public function getValideSaholtyBy(): ?User { return $this->valideSaholtyBy; }
    public function setValideSaholtyBy(?User $user): static { $this->valideSaholtyBy = $user; return $this; }

    public function getValideSaholtyLe(): ?\DateTime { return $this->valideSaholtyLe; }
    public function setValideSaholtyLe(?\DateTime $dt): static { $this->valideSaholtyLe = $dt; return $this; }

    public function getNoteTransmission(): ?string { return $this->noteTransmission; }
    public function setNoteTransmission(?string $note): static { $this->noteTransmission = $note; return $this; }

    public function getStatut(): string { return $this->statut; }
    public function setStatut(string $statut): static { $this->statut = $statut; return $this; }

    public function getDateCloture(): ?\DateTime { return $this->dateCloture; }
    public function setDateCloture(?\DateTime $dt): static { $this->dateCloture = $dt; return $this; }

    public function cloturer(): static
    {
        $this->statut = self::STATUT_CLOTURE;
        $this->dateCloture = new \DateTime();
        return $this;
    }

    /**
     * Réouvre une transmission clôturée (remet au statut "en cours de résolution").
     */
    public function rouvrir(): static
    {
        $this->statut = self::STATUT_EN_COURS;
        $this->dateCloture = null;
        return $this;
    }

    public function isCloture(): bool
    {
        return $this->statut === self::STATUT_CLOTURE;
    }

    public function isEnCours(): bool
    {
        return $this->statut === self::STATUT_EN_COURS;
    }

    public function getStatutLabel(): string
    {
        return match ($this->statut) {
            self::STATUT_CLOTURE => 'Clôturé',
            self::STATUT_EN_COURS => 'En cours de résolution',
            'valide' => 'Validé',
            'rejete' => 'Rejeté',
            default => 'En cours de résolution',
        };
    }

    public function getManager(): ?User
    {
        return $this->transmisBy;
    }

    public function isValide(): bool
    {
        return $this->statut === 'valide';
    }
}
