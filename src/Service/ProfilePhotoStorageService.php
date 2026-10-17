<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ProfilePhotoStorageService
{
    private const MAX_FILE_SIZE = 5_242_880;
    private const MAX_IMAGE_PIXELS = 16_000_000;

    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(
        private readonly string $storageDirectory,
    ) {
    }

    public function store(UploadedFile $file, int $userId): string
    {
        if (!$file->isValid()) {
            throw new \InvalidArgumentException('La photo n’a pas pu être téléversée correctement.');
        }

        $size = $file->getSize();
        if ($size === false || $size < 1 || $size > self::MAX_FILE_SIZE) {
            throw new \InvalidArgumentException('La photo doit peser au maximum 5 Mo.');
        }

        $imageInfo = @getimagesize($file->getPathname());
        $mimeType = $imageInfo['mime'] ?? null;
        if (!is_string($mimeType) || !isset(self::MIME_EXTENSIONS[$mimeType])) {
            throw new \InvalidArgumentException('Format non accepté. Choisissez une image JPEG, PNG ou WebP.');
        }

        $width = (int) ($imageInfo[0] ?? 0);
        $height = (int) ($imageInfo[1] ?? 0);
        if ($width < 1 || $height < 1 || $width > 4096 || $height > 4096 || $width * $height > self::MAX_IMAGE_PIXELS) {
            throw new \InvalidArgumentException('Les dimensions de la photo sont trop importantes.');
        }

        $directory = $this->userDirectory($userId);
        $this->ensureDirectory($directory);
        $filename = bin2hex(random_bytes(16)) . '.' . self::MIME_EXTENSIONS[$mimeType];
        $file->move($directory, $filename);

        return $userId . '/' . $filename;
    }

    public function resolve(string $storedPath, int $userId): ?string
    {
        if (!preg_match('/\A' . preg_quote((string) $userId, '/') . '\/[a-f0-9]{32}\.(jpg|png|webp)\z/', $storedPath)) {
            return null;
        }

        $path = $this->userDirectory($userId) . DIRECTORY_SEPARATOR . basename($storedPath);
        return is_file($path) && is_readable($path) ? $path : null;
    }

    public function mimeType(string $storedPath): string
    {
        return match (strtolower(pathinfo($storedPath, PATHINFO_EXTENSION))) {
            'jpg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => throw new \InvalidArgumentException('Format de photo enregistré non valide.'),
        };
    }

    public function remove(?string $storedPath, int $userId): void
    {
        if ($storedPath === null) {
            return;
        }

        $path = $this->resolve($storedPath, $userId);
        if ($path !== null && !unlink($path)) {
            throw new \RuntimeException('Impossible de supprimer l’ancienne photo de profil.');
        }
    }

    private function userDirectory(int $userId): string
    {
        return $this->storageDirectory . DIRECTORY_SEPARATOR . $userId;
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Impossible de créer le stockage privé des photos de profil.');
        }
    }
}
