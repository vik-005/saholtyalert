<?php

namespace App\Entity;

use App\Repository\ImportBatchRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use JsonException;

#[ORM\Entity(repositoryClass: ImportBatchRepository::class)]
#[ORM\Table(name: 'import_batch')]
#[ORM\Index(columns: ['status'], name: 'idx_import_status')]
#[ORM\Index(columns: ['created_at'], name: 'idx_import_created')]
class ImportBatch
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_ERROR = 'error';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 64, unique: true)]
    private string $batchId;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $filename;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $status;

    #[ORM\Column(type: Types::INTEGER)]
    private int $linesTotal = 0;

    #[ORM\Column(type: Types::INTEGER)]
    private int $linesSuccess = 0;

    #[ORM\Column(type: Types::INTEGER)]
    private int $linesError = 0;

    #[ORM\Column(type: Types::INTEGER)]
    private int $linesUpdated = 0;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $summary = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $errors = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->status = self::STATUS_PENDING;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBatchId(): string
    {
        return $this->batchId;
    }

    public function setBatchId(string $batchId): self
    {
        $this->batchId = $batchId;
        return $this;
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

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function setFilename(string $filename): self
    {
        $this->filename = $filename;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getLinesTotal(): int
    {
        return $this->linesTotal;
    }

    public function setLinesTotal(int $linesTotal): self
    {
        $this->linesTotal = $linesTotal;
        return $this;
    }

    public function getLinesSuccess(): int
    {
        return $this->linesSuccess;
    }

    public function setLinesSuccess(int $linesSuccess): self
    {
        $this->linesSuccess = $linesSuccess;
        return $this;
    }

    public function getLinesError(): int
    {
        return $this->linesError;
    }

    public function setLinesError(int $linesError): self
    {
        $this->linesError = $linesError;
        return $this;
    }

    public function getLinesUpdated(): int
    {
        return $this->linesUpdated;
    }

    public function setLinesUpdated(int $linesUpdated): self
    {
        $this->linesUpdated = $linesUpdated;
        return $this;
    }

    /**
     * @return array|null [success_count, update_count, error_count, lignes_incompletes_count]
     */
    public function getSummary(): ?array
    {
        return $this->summary;
    }

    /**
     * @param array|null $summary [success_count, update_count, error_count, lignes_incompletes_count]
     */
    public function setSummary(?array $summary): self
    {
        $this->summary = $summary;
        return $this;
    }

    /**
     * @return array|null List of error messages
     */
    public function getErrors(): ?array
    {
        return $this->errors;
    }

    /**
     * @param array|null $errors List of error messages
     */
    public function setErrors(?array $errors): self
    {
        $this->errors = $errors;
        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    /**
     * @throws JsonException
     */
    public function updateStatus(string $status, array $summary = null, array $errors = null): self
    {
        $this->status = $status;
        if ($summary !== null) {
            $this->summary = $summary;
        }
        if ($errors !== null) {
            $this->errors = $errors;
        }
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }
}
