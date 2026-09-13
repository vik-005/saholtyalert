<?php

namespace App\Entity;

use App\Enum\NiveauPriorite;
use Doctrine\ORM\Mapping as ORM;

/**
 * Historique complet des calculs de score GEI.
 * Jamais d'écrasement — chaque recalcul crée une nouvelle entrée (audit du raisonnement).
 */
#[ORM\Entity]
#[ORM\Table(name: 'alert_qualification_history')]
class AlertQualificationHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Alert::class, inversedBy: 'qualificationHistories')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Alert $alert = null;

    #[ORM\Column(type: 'integer')]
    private int $scoreGei;

    /** Score avant recalcul ; null pour le premier calcul de l'alerte. */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $oldScore = null;

    /** Score courant issu de ce recalcul (explicite pour l'audit). */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $newScore = null;

    #[ORM\Column(type: 'string', enumType: NiveauPriorite::class)]
    private NiveauPriorite $niveauPriorite;

    /** 'system' ou l'ID de l'utilisateur */
    #[ORM\Column(type: 'string', length: 50)]
    private string $calculePar = 'system';

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $calculeLe;

    /** Snapshot des critères de calcul pour traçabilité */
    #[ORM\Column(type: 'json')]
    private array $criteresSnapshot = [];

    public function __construct()
    {
        $this->calculeLe = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getAlert(): ?Alert { return $this->alert; }
    public function setAlert(?Alert $alert): static { $this->alert = $alert; return $this; }

    public function getScoreGei(): int { return $this->scoreGei; }
    public function setScoreGei(int $scoreGei): static { $this->scoreGei = $scoreGei; return $this; }

    public function getOldScore(): ?int { return $this->oldScore; }
    public function setOldScore(?int $oldScore): static { $this->oldScore = $oldScore; return $this; }

    public function getNewScore(): ?int { return $this->newScore; }
    public function setNewScore(?int $newScore): static { $this->newScore = $newScore; return $this; }

    public function getNiveauPriorite(): NiveauPriorite { return $this->niveauPriorite; }
    public function setNiveauPriorite(NiveauPriorite $niveauPriorite): static { $this->niveauPriorite = $niveauPriorite; return $this; }

    public function getCalculePar(): string { return $this->calculePar; }
    public function setCalculePar(string $calculePar): static { $this->calculePar = $calculePar; return $this; }

    public function getCalculeLe(): \DateTimeImmutable { return $this->calculeLe; }

    public function getCriteresSnapshot(): array { return $this->criteresSnapshot; }
    public function setCriteresSnapshot(array $criteresSnapshot): static { $this->criteresSnapshot = $criteresSnapshot; return $this; }
}
