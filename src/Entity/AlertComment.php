<?php

namespace App\Entity;

use App\Repository\AlertCommentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AlertCommentRepository::class)]
#[ORM\Table(name: 'alert_comment')]
#[ORM\Index(name: 'idx_ac_alert', columns: ['alert_id'])]
#[ORM\HasLifecycleCallbacks]
class AlertComment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Alert::class, inversedBy: 'commentairesHistorises')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Alert $alert = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $auteur = null;

    #[ORM\Column(type: 'text')]
    private string $contenu;

    #[ORM\Column(type: 'string', length: 20)]
    private string $type = 'agent';

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

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

    public function getAuteur(): ?User
    {
        return $this->auteur;
    }

    public function setAuteur(?User $auteur): static
    {
        $this->auteur = $auteur;
        return $this;
    }

    public function getContenu(): string
    {
        return $this->contenu;
    }

    public function setContenu(string $contenu): static
    {
        $this->contenu = $contenu;
        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
