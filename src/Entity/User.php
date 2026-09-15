<?php

namespace App\Entity;

use App\Entity\ConnectionPosition;
use App\Enum\UserRoleEnum;
use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfiguration;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfigurationInterface;
use Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['email'], message: 'Cet email est déjà utilisé.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface, TwoFactorInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 180, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    private string $email;

    #[ORM\Column(type: 'string', length: 100)]
    #[Assert\NotBlank]
    private string $prenom;

    #[ORM\Column(type: 'string', length: 100)]
    #[Assert\NotBlank]
    private string $nom;

    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private ?string $telephone = null;

    #[ORM\Column(type: 'string', length: 150, nullable: true)]
    private ?string $fonction = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $photoProfil = null;

    #[ORM\Column(type: 'string', enumType: UserRoleEnum::class)]
    private UserRoleEnum $role = UserRoleEnum::EMETTEUR_TERRAIN;

    #[ORM\Column(type: 'json')]
    private array $roles = [];

    #[ORM\Column(type: 'string')]
    private string $password;

    #[ORM\ManyToOne(targetEntity: Market::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Market $market = null;

    /** Marchés gérés par le Manager (Many-to-Many, Annexe D & Spec A.2) */
    #[ORM\ManyToMany(targetEntity: Market::class)]
    #[ORM\JoinTable(name: 'manager_marches')]
    private Collection $markets;

    /**
     * Marchés assignés à l'Agent (Many-to-Many, Partie C — prompt expert final).
     * Un Agent peut être assigné à plusieurs marchés ; un marché peut avoir plusieurs Agents.
     * L'assignation est toujours faite par le Manager créateur (jamais auto-inscription).
     */
    #[ORM\ManyToMany(targetEntity: Market::class)]
    #[ORM\JoinTable(name: 'agent_marches')]
    private Collection $agentMarkets;

    /**
     * Manager qui a créé ce compte (Partie C — un Agent ne peut pas s'auto-inscrire).
     * Reste null pour les Managers, SAHOLTY et SUPERADMIN.
     */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdByManager = null;

    #[ORM\Column(type: 'boolean')]
    private bool $actif = true;

    #[ORM\Column(type: 'encrypted_string', length: 255, nullable: true)]
    private ?string $totpSecret = null;

    #[ORM\Column(type: 'boolean')]
    private bool $totpEnabled = false;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTime $lastLoginAt = null;

    #[ORM\OneToMany(mappedBy: 'emetteur', targetEntity: Alert::class)]
    private Collection $alertesEmises;

    #[ORM\OneToMany(mappedBy: 'responsableSuivi', targetEntity: Alert::class)]
    private Collection $alertesSuivies;

    #[ORM\OneToMany(mappedBy: 'user', targetEntity: ConnectionPosition::class, cascade: ['remove'])]
    #[ORM\OrderBy(['connectedAt' => 'DESC'])]
    private Collection $connectionPositions;

    public function __construct()
    {
        $this->alertesEmises       = new ArrayCollection();
        $this->alertesSuivies      = new ArrayCollection();
        $this->markets             = new ArrayCollection();
        $this->agentMarkets        = new ArrayCollection();
        $this->connectionPositions = new ArrayCollection();
        $this->createdAt           = new \DateTimeImmutable();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->syncRolesFromEnum();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->syncRolesFromEnum();
    }

    /** Synchronise le champ JSON roles depuis l'enum role */
    private function syncRolesFromEnum(): void
    {
        $this->roles = $this->role->symfonyRoles();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function getPrenom(): string
    {
        return $this->prenom;
    }

    public function setPrenom(string $prenom): static
    {
        $this->prenom = $prenom;
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

    public function getNomComplet(): string
    {
        return $this->prenom . ' ' . $this->nom;
    }

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function setTelephone(?string $telephone): static
    {
        $this->telephone = $telephone;
        return $this;
    }

    public function getFonction(): ?string
    {
        return $this->fonction;
    }

    public function setFonction(?string $fonction): static
    {
        $this->fonction = $fonction;
        return $this;
    }

    public function getPhotoProfil(): ?string
    {
        return $this->photoProfil;
    }

    public function setPhotoProfil(?string $photoProfil): static
    {
        $this->photoProfil = $photoProfil;
        return $this;
    }

    public function getRole(): UserRoleEnum
    {
        return $this->role;
    }

    public function setRole(UserRoleEnum $role): static
    {
        $this->role = $role;
        $this->syncRolesFromEnum();
        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';
        return array_unique($roles);
    }

    public function setRoles(array $roles): static
    {
        $this->roles = $roles;
        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;
        return $this;
    }

    public function eraseCredentials(): void
    {
        // Aucun credential en clair à effacer
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

    /** @return Collection<int, Market> */
    public function getMarkets(): Collection
    {
        return $this->markets;
    }

    public function addMarket(Market $market): static
    {
        if (!$this->markets->contains($market)) {
            $this->markets->add($market);
        }
        return $this;
    }

    public function removeMarket(Market $market): static
    {
        $this->markets->removeElement($market);
        return $this;
    }

    /** Retourne la liste de tous les marchés gérés (marchés de la collection + marché principal) */
    public function getAllManagedMarkets(): array
    {
        $result = $this->markets->toArray();
        if ($this->market && !in_array($this->market, $result, true)) {
            $result[] = $this->market;
        }
        return $result;
    }

    // ── Marchés Agent (N-N) ────────────────────────────────────────────────────

    /** @return Collection<int, Market> */
    public function getAgentMarkets(): Collection
    {
        return $this->agentMarkets;
    }

    public function addAgentMarket(Market $market): static
    {
        if (!$this->agentMarkets->contains($market)) {
            $this->agentMarkets->add($market);
        }
        return $this;
    }

    public function removeAgentMarket(Market $market): static
    {
        $this->agentMarkets->removeElement($market);
        return $this;
    }

    /**
     * Retourne tous les marchés auxquels l'Agent a accès.
     * Pour un Agent : collection agentMarkets + marché principal legacy (compat).
     * Pour un Manager : utiliser getAllManagedMarkets().
     */
    public function getAllAgentMarkets(): array
    {
        $result = $this->agentMarkets->toArray();
        if ($this->market && !in_array($this->market, $result, true)) {
            $result[] = $this->market;
        }
        return $result;
    }

    // ── Manager créateur ───────────────────────────────────────────────────────

    public function getCreatedByManager(): ?User
    {
        return $this->createdByManager;
    }

    public function setCreatedByManager(?User $manager): static
    {
        $this->createdByManager = $manager;
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

    public function getTotpSecret(): ?string
    {
        return $this->totpSecret;
    }

    public function setTotpSecret(?string $totpSecret): static
    {
        $this->totpSecret = $totpSecret;
        return $this;
    }

    public function isTotpAuthenticationEnabled(): bool
    {
        return $this->totpEnabled;
    }

    public function getTotpAuthenticationUsername(): string
    {
        return $this->email;
    }

    public function getTotpAuthenticationConfiguration(): TotpConfigurationInterface|null
    {
        if ($this->totpSecret === null) {
            return null;
        }

        return new TotpConfiguration($this->totpSecret, TotpConfiguration::ALGORITHM_SHA1, 30, 6);
    }

    public function isTotpEnabled(): bool
    {
        return $this->totpEnabled;
    }

    public function setTotpEnabled(bool $totpEnabled): static
    {
        $this->totpEnabled = $totpEnabled;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastLoginAt(): ?\DateTime
    {
        return $this->lastLoginAt;
    }

    public function setLastLoginAt(?\DateTime $lastLoginAt): static
    {
        $this->lastLoginAt = $lastLoginAt;
        return $this;
    }

    public function getAlertesEmises(): Collection
    {
        return $this->alertesEmises;
    }

    public function getAlertesSuivies(): Collection
    {
        return $this->alertesSuivies;
    }

    /** @return Collection<int, ConnectionPosition> */
    public function getConnectionPositions(): Collection
    {
        return $this->connectionPositions;
    }

    public function __toString(): string
    {
        return $this->getNomComplet() . ' (' . $this->email . ')';
    }
}
