<?php

namespace App\Entity;

use App\Repository\MarketRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: MarketRepository::class)]
#[ORM\Table(name: 'market')]
class Market
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 3, unique: true)]
    #[Assert\Length(exactly: 3)]
    #[Assert\NotBlank]
    private string $codeIso3;

    #[ORM\Column(type: 'string', length: 100)]
    #[Assert\NotBlank]
    private string $nom;

    #[ORM\Column(type: 'string', length: 50)]
    private string $region = 'Afrique de l\'Ouest';

    #[ORM\Column(type: 'boolean')]
    private bool $actif = true;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCodeIso3(): string
    {
        return $this->codeIso3;
    }

    public function setCodeIso3(string $codeIso3): static
    {
        $this->codeIso3 = strtoupper($codeIso3);
        return $this;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;
        return $this;
    }

    public function getRegion(): string
    {
        return $this->region;
    }

    public function setRegion(string $region): static
    {
        $this->region = $region;
        return $this;
    }

    public function isActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): static
    {
        $this->actif = $actif;
        return $this;
    }

    public function __toString(): string
    {
        return $this->nom;
    }
}
