<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Journal de traçabilité obligatoire (Annexe D §6/§8).
 * Toute lecture, modification, export ou transmission est journalisée sans exception.
 */
#[ORM\Entity]
#[ORM\Table(name: 'access_log')]
class AccessLog
{
    public const ACTION_LECTURE = 'lecture';
    public const ACTION_MODIFICATION = 'modification';
    public const ACTION_CREATION = 'creation';
    public const ACTION_SUPPRESSION = 'suppression';
    public const ACTION_EXPORT = 'export';
    public const ACTION_TRANSMISSION = 'transmission';
    public const ACTION_VUE_SOURCE = 'vue_source';
    public const ACTION_LOGIN = 'login';
    public const ACTION_LOGOUT = 'logout';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Alert::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Alert $alert = null;

    #[ORM\Column(type: 'string', length: 50)]
    private string $action;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $dateAction;

    #[ORM\Column(type: 'string', length: 45, nullable: true)]
    private ?string $ipAdresse = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $userAgent = null;

    /** success, denied, error */
    #[ORM\Column(type: 'string', length: 20)]
    private string $resultat = 'success';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $details = null;

    public function __construct()
    {
        $this->dateAction = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): static { $this->user = $user; return $this; }

    public function getAlert(): ?Alert { return $this->alert; }
    public function setAlert(?Alert $alert): static { $this->alert = $alert; return $this; }

    public function getAction(): string { return $this->action; }
    public function setAction(string $action): static { $this->action = $action; return $this; }

    public function getDateAction(): \DateTimeImmutable { return $this->dateAction; }

    public function getIpAdresse(): ?string { return $this->ipAdresse; }
    public function setIpAdresse(?string $ipAdresse): static { $this->ipAdresse = $ipAdresse; return $this; }

    public function getUserAgent(): ?string { return $this->userAgent; }
    public function setUserAgent(?string $userAgent): static { $this->userAgent = $userAgent; return $this; }

    public function getResultat(): string { return $this->resultat; }
    public function setResultat(string $resultat): static { $this->resultat = $resultat; return $this; }

    public function getDetails(): ?string { return $this->details; }
    public function setDetails(?string $details): static { $this->details = $details; return $this; }
}
