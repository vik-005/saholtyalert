<?php

namespace App\Message;

/**
 * Message pour l'import Excel asynchrone.
 *
 * Utilisé pour traiter les fichiers volumineux (>500 lignes) sans bloquer la requête HTTP.
 */
class ImportExcelMessage
{
    private string $filePath;
    private int $userId;
    private ?string $batchId = null;

    public function __construct(string $filePath, int $userId, ?string $batchId = null)
    {
        $this->filePath = $filePath;
        $this->userId = $userId;
        $this->batchId = $batchId;
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getBatchId(): ?string
    {
        return $this->batchId;
    }

    public function setBatchId(?string $batchId): self
    {
        $this->batchId = $batchId;
        return $this;
    }
}
