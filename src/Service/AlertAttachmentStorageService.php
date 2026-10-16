<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;

class AlertAttachmentStorageService
{
    private const MAX_FILE_SIZE = 15728640;

    private const ALLOWED_MIME_TYPES = [
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword', 'application/x-ole-storage', 'application/vnd.ms-office'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xls' => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/vnd.ms-office'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
    ];

    public function __construct(
        private readonly string $storageDirectory,
        private readonly CryptoService $cryptoService,
    ) {
    }

    /**
     * @return array{token: string, originalName: string, mimeType: string, size: int}
     */
    public function stage(UploadedFile $file): array
    {
        $this->cleanExpiredStagedFiles();

        if (!$file->isValid()) {
            throw new \InvalidArgumentException('Le fichier transmis est invalide ou incomplet.');
        }

        $size = $file->getSize();
        if ($size === false || $size < 1 || $size > self::MAX_FILE_SIZE) {
            throw new \InvalidArgumentException('Le justificatif doit peser au maximum 15 Mo.');
        }

        $originalName = $this->sanitizeOriginalName($file->getClientOriginalName());
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $detectedMimeType = $file->getMimeType() ?? '';
        if (!isset(self::ALLOWED_MIME_TYPES[$extension]) || !in_array($detectedMimeType, self::ALLOWED_MIME_TYPES[$extension], true)) {
            throw new \InvalidArgumentException('Format non accepté. Utilisez un fichier PDF, Word ou Excel.');
        }
        $mimeType = match ($extension) {
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        };

        $token = bin2hex(random_bytes(16));
        $pendingDirectory = $this->storageDirectory . DIRECTORY_SEPARATOR . 'pending';
        $this->ensureDirectory($pendingDirectory);
        $file->move($pendingDirectory, $token);

        return [
            'token' => $token,
            'originalName' => $originalName,
            'mimeType' => $mimeType,
            'size' => $size,
        ];
    }

    public function copyToPermanent(string $token, string $originalName): string
    {
        $token = $this->validateToken($token);
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!isset(self::ALLOWED_MIME_TYPES[$extension])) {
            throw new \InvalidArgumentException('Extension de justificatif non autorisée.');
        }

        $source = $this->pendingPath($token);
        if (!is_file($source)) {
            throw new \RuntimeException('Le justificatif temporaire a expiré. Veuillez le joindre à nouveau.');
        }

        $filename = $token . '.' . $extension;
        $destinationDirectory = $this->storageDirectory . DIRECTORY_SEPARATOR . 'attachments';
        $this->ensureDirectory($destinationDirectory);
        $destination = $destinationDirectory . DIRECTORY_SEPARATOR . $filename;
        if (!copy($source, $destination)) {
            throw new \RuntimeException('Impossible d’enregistrer le justificatif de façon sécurisée.');
        }

        return $this->cryptoService->encrypt('attachments/' . $filename);
    }

    public function resolveStoredPath(string $encryptedPath): ?string
    {
        $relativePath = $this->cryptoService->decrypt($encryptedPath);
        if ($relativePath === null || !preg_match('/\Aattachments\/[a-f0-9]{32}\.(pdf|doc|docx|xls|xlsx)\z/', $relativePath)) {
            return null;
        }

        $path = $this->storageDirectory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        return is_file($path) ? $path : null;
    }

    public function removeStaged(string $token): void
    {
        $path = $this->pendingPath($this->validateToken($token));
        if (is_file($path) && !unlink($path)) {
            throw new \RuntimeException('Impossible de supprimer le fichier temporaire.');
        }
    }

    public function removeStored(string $encryptedPath): void
    {
        $path = $this->resolveStoredPath($encryptedPath);
        if ($path !== null && !unlink($path)) {
            throw new \RuntimeException('Impossible de supprimer le justificatif enregistré.');
        }
    }

    private function pendingPath(string $token): string
    {
        return $this->storageDirectory . DIRECTORY_SEPARATOR . 'pending' . DIRECTORY_SEPARATOR . $token;
    }

    private function validateToken(string $token): string
    {
        if (!preg_match('/\A[a-f0-9]{32}\z/', $token)) {
            throw new \InvalidArgumentException('Référence de fichier temporaire invalide.');
        }

        return $token;
    }

    private function sanitizeOriginalName(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        $name = basename($name);
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim($name);

        return mb_substr($name, 0, 255);
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Impossible de créer le répertoire privé des justificatifs.');
        }
    }

    private function cleanExpiredStagedFiles(): void
    {
        $pendingDirectory = $this->storageDirectory . DIRECTORY_SEPARATOR . 'pending';
        if (!is_dir($pendingDirectory)) {
            return;
        }

        $expiration = time() - 7 * 86400;
        foreach (glob($pendingDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
            if (!is_file($path)) {
                continue;
            }

            $modifiedAt = filemtime($path);
            if ($modifiedAt !== false && $modifiedAt < $expiration && !unlink($path)) {
                throw new \RuntimeException('Impossible de nettoyer un justificatif temporaire expiré.');
            }
        }
    }
}
