<?php

namespace App\Entity;

use App\Repository\ConnectionPositionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ConnectionPositionRepository::class)]
#[ORM\Table(name: 'connection_position')]
class ConnectionPosition
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'connectionPositions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** Coordonnées géographiques obtenues via API navigateur ou IP fallback */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 6, options: ['default' => '0.000000'])]
    private string $latitude = '0.000000';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 6, options: ['default' => '0.000000'])]
    private string $longitude = '0.000000';

    /** Méthode de géolocalisation (geolocation_api ou ip_lookup) */
    #[ORM\Column(type: 'string', length: 20)]
    private string $method = 'geolocation_api';

    /** IP utilisateur (pour IP fallback) */
    #[ORM\Column(type: 'string', length: 45, nullable: true)]
    private ?string $ipAddress = null;

    /** Heure de connexion */
    #[ORM\Column(type: 'datetime')]
    private \DateTime $connectedAt;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function getLatitude(): float
    {
        return (float) $this->latitude;
    }

    public function setLatitude(float $latitude): self
    {
        $this->latitude = (string) $latitude;
        return $this;
    }

    public function getLongitude(): float
    {
        return (float) $this->longitude;
    }

    public function setLongitude(float $longitude): self
    {
        $this->longitude = (string) $longitude;
        return $this;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function setMethod(string $method): self
    {
        $this->method = $method;
        return $this;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(?string $ipAddress): self
    {
        $this->ipAddress = $ipAddress;
        return $this;
    }

    public function getConnectedAt(): \DateTime
    {
        return $this->connectedAt;
    }

    public function setConnectedAt(\DateTime $connectedAt): self
    {
        $this->connectedAt = $connectedAt;
        return $this;
    }
}
