<?php

namespace App\Entity;

use App\Repository\ZoneGeoRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ZoneGeoRepository::class)]
#[ORM\Table(name: 'zone_geo')]
class ZoneGeo
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'zoneGeo', targetEntity: Market::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Market $market;

    /** Coordonnées géographiques du centre de la zone */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 6, options: ['default' => '0.000000'])]
    private string $latitude = '0.000000';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 6, options: ['default' => '0.000000'])]
    private string $longitude = '0.000000';

    /** Polygone décrivant la zone (WKT format) - optionnel pour des zones complexes */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $polygonWkt = null;

    /** Nom de la zone (dérivé du marché, mais stocké pour éviter join) */
    #[ORM\Column(type: 'string', length: 100)]
    private string $nom;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMarket(): Market
    {
        return $this->market;
    }

    public function setMarket(Market $market): self
    {
        $this->market = $market;
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

    public function getPolygonWkt(): ?string
    {
        return $this->polygonWkt;
    }

    public function setPolygonWkt(?string $polygonWkt): self
    {
        $this->polygonWkt = $polygonWkt;
        return $this;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(string $nom): self
    {
        $this->nom = $nom;
        return $this;
    }
}
