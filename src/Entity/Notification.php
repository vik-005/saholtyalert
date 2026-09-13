<?php

namespace App\Entity;

use App\Repository\NotificationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\Table(name: 'notification')]
class Notification
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $destinataire = null;

    /** info, warning, danger, success — et types urgence : 'urgence', 'sla_proche', 'rejet', 'validation' */
    #[ORM\Column(type: 'string', length: 50)]
    private string $type = 'info';

    #[ORM\ManyToOne(targetEntity: Alert::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Alert $alert = null;

    #[ORM\Column(type: 'text')]
    private string $contenu;

    #[ORM\Column(type: 'boolean')]
    private bool $lu = false;

    /**
     * Priorité d'affichage : 0 = normale, 1 = haute (urgence critique).
     * Les notifications haute priorité s'affichent en tête de liste (Partie H).
     */
    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $priorite = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getDestinataire(): ?User { return $this->destinataire; }
    public function setDestinataire(?User $destinataire): static { $this->destinataire = $destinataire; return $this; }

    public function getType(): string { return $this->type; }
    public function setType(string $type): static { $this->type = $type; return $this; }

    public function getAlert(): ?Alert { return $this->alert; }
    public function setAlert(?Alert $alert): static { $this->alert = $alert; return $this; }

    public function getContenu(): string { return $this->contenu; }
    public function setContenu(string $contenu): static { $this->contenu = $contenu; return $this; }

    public function isLu(): bool { return $this->lu; }
    public function setLu(bool $lu): static { $this->lu = $lu; return $this; }

    public function getPriorite(): int { return $this->priorite; }
    public function setPriorite(int $priorite): static { $this->priorite = $priorite; return $this; }
    public function isUrgente(): bool { return $this->priorite >= 1; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
