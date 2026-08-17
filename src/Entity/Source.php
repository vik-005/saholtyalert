<?php

namespace App\Entity;

use App\Enum\FiabiliteSource;
use Doctrine\ORM\Mapping as ORM;

/**
 * Métadonnées de source uniquement — jamais l'identité en clair (Annexe D §6).
 * L'identité réelle, si nécessaire, est dans source_identity chiffrée (hors scope initial).
 */
#[ORM\Entity]
#[ORM\Table(name: 'source')]
#[ORM\HasLifecycleCallbacks]
class Source
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 30, unique: true)]
    private string $codeAnonyme;

    #[ORM\Column(type: 'string', enumType: FiabiliteSource::class)]
    private FiabiliteSource $fiabilite = FiabiliteSource::C;

    /** connue_fiable, moyenne, non_verifiee */
    #[ORM\Column(type: 'string', length: 50)]
    private string $historique = 'non_verifiee';

    #[ORM\ManyToOne(targetEntity: Market::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Market $pays = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $creeLe;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->creeLe = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCodeAnonyme(): string
    {
        return $this->codeAnonyme;
    }

    public function setCodeAnonyme(string $codeAnonyme): static
    {
        $this->codeAnonyme = $codeAnonyme;
        return $this;
    }

    public function getFiabilite(): FiabiliteSource
    {
        return $this->fiabilite;
    }

    public function setFiabilite(FiabiliteSource $fiabilite): static
    {
        $this->fiabilite = $fiabilite;
        return $this;
    }

    public function getHistorique(): string
    {
        return $this->historique;
    }

    public function setHistorique(string $historique): static
    {
        $this->historique = $historique;
        return $this;
    }

    public function getPays(): ?Market
    {
        return $this->pays;
    }

    public function setPays(?Market $pays): static
    {
        $this->pays = $pays;
        return $this;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function __toString(): string
    {
        return $this->codeAnonyme;
    }
}
