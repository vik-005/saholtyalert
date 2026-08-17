<?php

namespace App\Entity;

use App\Enum\AttachmentType;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'alert_attachment')]
#[ORM\HasLifecycleCallbacks]
class AlertAttachment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Alert::class, inversedBy: 'pieceJointes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Alert $alert = null;

    #[ORM\Column(type: 'string', enumType: AttachmentType::class)]
    private AttachmentType $type = AttachmentType::DOCUMENT;

    /** Chemin de stockage chiffré */
    #[ORM\Column(type: 'string', length: 512)]
    private string $fichierChemin;

    #[ORM\Column(type: 'string', length: 255)]
    private string $fichierNomOriginal;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $mimeType = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $tailleOctets = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $uploadedBy = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $uploadedAt;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->uploadedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getAlert(): ?Alert { return $this->alert; }
    public function setAlert(?Alert $alert): static { $this->alert = $alert; return $this; }

    public function getType(): AttachmentType { return $this->type; }
    public function setType(AttachmentType $type): static { $this->type = $type; return $this; }

    public function getFichierChemin(): string { return $this->fichierChemin; }
    public function setFichierChemin(string $fichierChemin): static { $this->fichierChemin = $fichierChemin; return $this; }

    public function getFichierNomOriginal(): string { return $this->fichierNomOriginal; }
    public function setFichierNomOriginal(string $nom): static { $this->fichierNomOriginal = $nom; return $this; }

    public function getMimeType(): ?string { return $this->mimeType; }
    public function setMimeType(?string $mimeType): static { $this->mimeType = $mimeType; return $this; }

    public function getTailleOctets(): ?int { return $this->tailleOctets; }
    public function setTailleOctets(?int $tailleOctets): static { $this->tailleOctets = $tailleOctets; return $this; }

    public function getUploadedBy(): ?User { return $this->uploadedBy; }
    public function setUploadedBy(?User $uploadedBy): static { $this->uploadedBy = $uploadedBy; return $this; }

    public function getUploadedAt(): \DateTimeImmutable { return $this->uploadedAt; }
}
