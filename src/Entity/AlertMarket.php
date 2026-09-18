<?php

namespace App\Entity;

use App\Entity\Alert;
use App\Entity\Market;
use App\Repository\AlertMarketRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AlertMarketRepository::class)]
#[ORM\Table(name: 'alert_market')]
#[ORM\UniqueConstraint(name: 'uniq_alert_market', columns: ['alert_id', 'market_id'])]
#[ORM\Index(name: 'idx_alert_market_role', columns: ['alert_id', 'role'])]
class AlertMarket
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Alert::class, inversedBy: 'alertMarkets')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Alert $alert = null;

    #[ORM\ManyToOne(targetEntity: Market::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Market $market = null;

    #[ORM\Column(type: 'string', length: 20)]
    private string $role = 'principal';

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $ordre = null;

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

    public function getMarket(): ?Market
    {
        return $this->market;
    }

    public function setMarket(?Market $market): static
    {
        $this->market = $market;
        return $this;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): static
    {
        $this->role = in_array($role, ['principal', 'associe'], true) ? $role : 'associe';
        return $this;
    }

    public function getOrdre(): ?int
    {
        return $this->ordre;
    }

    public function setOrdre(?int $ordre): static
    {
        $this->ordre = $ordre;
        return $this;
    }
}
