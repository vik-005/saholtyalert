<?php

namespace App\Entity;

use App\Enum\ActorRole;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'alert_actor')]
class AlertActor
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Alert::class, inversedBy: 'acteurs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Alert $alert = null;

    #[ORM\Column(type: 'string', length: 255)]
    #[Assert\NotBlank]
    private string $nomOuRaisonSociale;

    #[ORM\Column(type: 'string', enumType: ActorRole::class)]
    private ActorRole $role = ActorRole::AUTRE;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $pays = null;

    /** non_verifie, verifie, suspect */
    #[ORM\Column(type: 'string', length: 30)]
    private string $statutVerification = 'non_verifie';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAlert(): ?Alert
    {
        return $this->alert;
    }

    public function setAlert(?Alert $alert): static
    {
        $this->alert = $alert;
        return $this;
    }

    public function getNomOuRaisonSociale(): string
    {
        return $this->nomOuRaisonSociale;
    }

    public function setNomOuRaisonSociale(string $nomOuRaisonSociale): static
    {
        $this->nomOuRaisonSociale = $nomOuRaisonSociale;
        return $this;
    }

    public function getRole(): ActorRole
    {
        return $this->role;
    }

    public function setRole(ActorRole $role): static
    {
        $this->role = $role;
        return $this;
    }

    public function getPays(): ?string
    {
        return $this->pays;
    }

    public function setPays(?string $pays): static
    {
        $this->pays = $pays;
        return $this;
    }

    public function getStatutVerification(): string
    {
        return $this->statutVerification;
    }

    public function setStatutVerification(string $statutVerification): static
    {
        $this->statutVerification = $statutVerification;
        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;
        return $this;
    }
}
