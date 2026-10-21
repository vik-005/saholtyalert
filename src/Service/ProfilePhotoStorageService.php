<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ProfilePhotoStorageService
{
    private const MAX_FILE_SIZE = 5_242_880;
    private const MAX_IMAGE_PIXELS = 16_000_000;
    private const AVATAR_SIZE = 256;
    private const WEBP_QUALITY = 82;

    private const IMAGE_READERS = [
        'image/jpeg' => 'imagecreatefromjpeg',
        'image/png' => 'imagecreatefrompng',
        'image/webp' => 'imagecreatefromwebp',
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
        if (!is_array($imageInfo)) {
            throw new \InvalidArgumentException('Format non accepté. Choisissez une image JPEG, PNG ou WebP.');
        }

        $mimeType = $imageInfo['mime'];
        if (!isset(self::IMAGE_READERS[$mimeType])) {
            throw new \InvalidArgumentException('Format non accepté. Choisissez une image JPEG, PNG ou WebP.');
        }

        $width = (int) $imageInfo[0];
        $height = (int) $imageInfo[1];
        if ($width < 1 || $height < 1 || $width > 4096 || $height > 4096 || $width * $height > self::MAX_IMAGE_PIXELS) {
            throw new \InvalidArgumentException('Les dimensions de la photo sont trop importantes.');
        }

        $reader = self::IMAGE_READERS[$mimeType];
        if (!function_exists($reader) || !function_exists('imagewebp')) {
            throw new \RuntimeException('Le serveur ne peut pas optimiser ce format de photo.');
        }

        $directory = $this->userDirectory($userId);
        $this->ensureDirectory($directory);
        $filename = bin2hex(random_bytes(16)) . '.webp';
        $destination = $directory . DIRECTORY_SEPARATOR . $filename;
        $image = @$reader($file->getPathname());
        if ($image === false) {
            throw new \InvalidArgumentException('La photo ne peut pas être décodée. Choisissez un autre fichier.');
        }

        try {
            $this->writeOptimizedImage($image, $destination);
        } finally {
            imagedestroy($image);
        }

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

    public function resolveOptimized(string $storedPath, int $userId): ?string
    {
        $source = $this->resolve($storedPath, $userId);
        if ($source === null) {
            return null;
        }

        $optimized = $this->optimizedPath($source);
        if (!is_file($optimized)) {
            $imageInfo = @getimagesize($source);
            if (!is_array($imageInfo)) {
                throw new \RuntimeException('La photo de profil enregistrée ne peut pas être lue.');
            }

            $mimeType = $imageInfo['mime'];
            if ($mimeType === 'image/webp' && $imageInfo[0] === self::AVATAR_SIZE && $imageInfo[1] === self::AVATAR_SIZE) {
                return $source;
            }

            if (!isset(self::IMAGE_READERS[$mimeType])) {
                throw new \RuntimeException('Le format de la photo de profil enregistrée n’est pas pris en charge.');
            }

            $reader = self::IMAGE_READERS[$mimeType];
            if (!function_exists($reader) || !function_exists('imagewebp')) {
                throw new \RuntimeException('Le serveur ne peut pas optimiser cette photo de profil.');
            }

            $image = @$reader($source);
            if ($image === false) {
                throw new \RuntimeException('La photo de profil enregistrée ne peut pas être décodée.');
            }

            try {
                $temporary = $optimized . '.' . bin2hex(random_bytes(8)) . '.tmp';
                $this->writeOptimizedImage($image, $temporary);
                if (!is_file($optimized) && !rename($temporary, $optimized) && !is_file($optimized)) {
                    throw new \RuntimeException('Impossible de mettre en cache la vignette de la photo de profil.');
                }
            } finally {
                imagedestroy($image);
                if (isset($temporary) && is_file($temporary)) {
                    unlink($temporary);
                }
            }
        }

        return is_file($optimized) && is_readable($optimized) ? $optimized : null;
    }

    public function mimeType(string $storedPath): string
    {
        return 'image/webp';
    }

    public function remove(?string $storedPath, int $userId): void
    {
        if ($storedPath === null) {
            return;
        }

        $path = $this->resolve($storedPath, $userId);
        if ($path !== null) {
            $optimized = $this->optimizedPath($path);
            if (is_file($optimized) && !unlink($optimized)) {
                throw new \RuntimeException('Impossible de supprimer la vignette de la photo de profil.');
            }
            if (!unlink($path)) {
                throw new \RuntimeException('Impossible de supprimer l’ancienne photo de profil.');
            }
        }
    }

    private function writeOptimizedImage(\GdImage $source, string $destination): void
    {
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $cropSize = min($sourceWidth, $sourceHeight);
        $cropX = intdiv($sourceWidth - $cropSize, 2);
        $cropY = intdiv($sourceHeight - $cropSize, 2);
        $thumbnail = imagecreatetruecolor(self::AVATAR_SIZE, self::AVATAR_SIZE);
        if ($thumbnail === false) {
            throw new \RuntimeException('Impossible de préparer la vignette de la photo de profil.');
        }

        imagealphablending($thumbnail, false);
        imagesavealpha($thumbnail, true);
        $transparent = imagecolorallocatealpha($thumbnail, 0, 0, 0, 127);
        imagefill($thumbnail, 0, 0, $transparent);

        try {
            if (!imagecopyresampled(
                $thumbnail,
                $source,
                0,
                0,
                $cropX,
                $cropY,
                self::AVATAR_SIZE,
                self::AVATAR_SIZE,
                $cropSize,
                $cropSize,
            ) || !imagewebp($thumbnail, $destination, self::WEBP_QUALITY)) {
                throw new \RuntimeException('Impossible d’enregistrer la vignette optimisée de la photo.');
            }
        } finally {
            imagedestroy($thumbnail);
        }
    }

    private function optimizedPath(string $source): string
    {
        return dirname($source) . DIRECTORY_SEPARATOR . pathinfo($source, PATHINFO_FILENAME) . '-optimized.webp';
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
