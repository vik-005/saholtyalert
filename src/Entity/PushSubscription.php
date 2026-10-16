<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'push_subscription')]
#[ORM\UniqueConstraint(name: 'uniq_push_subscription_endpoint_hash', columns: ['endpoint_hash'])]
class PushSubscription
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'text')]
    private string $endpoint;

    #[ORM\Column(name: 'endpoint_hash', type: 'string', length: 64)]
    private string $endpointHash;

    #[ORM\Column(name: 'public_key', type: 'string', length: 255)]
    private string $publicKey;

    #[ORM\Column(name: 'auth_token', type: 'string', length: 255)]
    private string $authToken;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, string $endpoint, string $publicKey, string $authToken)
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updateSubscription($user, $endpoint, $publicKey, $authToken);
    }

    public function updateSubscription(User $user, string $endpoint, string $publicKey, string $authToken): void
    {
        $this->user = $user;
        $this->endpoint = $endpoint;
        $this->endpointHash = hash('sha256', $endpoint);
        $this->publicKey = $publicKey;
        $this->authToken = $authToken;
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getEndpoint(): string { return $this->endpoint; }
    public function getEndpointHash(): string { return $this->endpointHash; }
    public function getPublicKey(): string { return $this->publicKey; }
    public function getAuthToken(): string { return $this->authToken; }
}
