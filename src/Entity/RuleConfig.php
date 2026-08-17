<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Table de configuration du moteur de règles, éditable en interface superadmin.
 * Permet de modifier les seuils sans toucher au code (§3.2 + §4.4 spec).
 */
#[ORM\Entity(repositoryClass: \App\Repository\RuleConfigRepository::class)]
#[ORM\Table(name: 'rule_config')]
class RuleConfig
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 100, unique: true)]
    #[Assert\NotBlank]
    private string $cle;

    #[ORM\Column(type: 'string', length: 50)]
    #[Assert\NotBlank]
    private string $valeur;

    #[ORM\Column(type: 'string', length: 255)]
    private string $description;

    #[ORM\Column(type: 'string', length: 50)]
    private string $categorie = 'general';

    #[ORM\Column(type: 'boolean')]
    private bool $modifiable = true;

    #[ORM\Column(type: 'boolean')]
    private bool $actif = true;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCle(): string
    {
        return $this->cle;
    }

    public function setCle(string $cle): static
    {
        $this->cle = $cle;
        return $this;
    }

    public function getValeur(): string
    {
        return $this->valeur;
    }

    public function getValeurInt(): int
    {
        return (int) $this->valeur;
    }

    public function setValeur(string $valeur): static
    {
        $this->valeur = $valeur;
        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getCategorie(): string
    {
        return $this->categorie;
    }

    public function setCategorie(string $categorie): static
    {
        $this->categorie = $categorie;
        return $this;
    }

    public function isModifiable(): bool
    {
        return $this->modifiable;
    }

    public function setModifiable(bool $modifiable): static
    {
        $this->modifiable = $modifiable;
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
}
