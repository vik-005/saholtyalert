<?php

namespace App\Type;

use App\Service\CryptoService;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\StringType;

class EncryptedType extends StringType
{
    public const NAME = 'encrypted_string';

    private static ?CryptoService $cryptoService = null;
    private static bool $cryptoServiceFailed = false;

    public function __construct()
    {
    }

    public static function setCryptoService(?CryptoService $cryptoService): void
    {
        self::$cryptoService = $cryptoService;
    }

    private function getCryptoService(): CryptoService
    {
        if (self::$cryptoService !== null) {
            return self::$cryptoService;
        }

        if (self::$cryptoServiceFailed) {
            throw new \RuntimeException('CryptoService is not configured for EncryptedType. Make sure APP_ENCRYPTION_KEY is set.');
        }

        $key = $_ENV['APP_ENCRYPTION_KEY'] ?? $_SERVER['APP_ENCRYPTION_KEY'] ?? getenv('APP_ENCRYPTION_KEY') ?: null;
        if ($key) {
            self::$cryptoService = new CryptoService($key);
            return self::$cryptoService;
        }

        self::$cryptoServiceFailed = true;
        throw new \RuntimeException('CryptoService is not configured for EncryptedType. Make sure APP_ENCRYPTION_KEY is set.');
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $decrypted = $this->getCryptoService()->decrypt((string) $value);

        if ($decrypted === null) {
            throw new \InvalidArgumentException('Failed to decrypt value. The encryption key may have changed.');
        }

        return $decrypted;
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->getCryptoService()->encrypt((string) $value);
    }

    public function requiresSQLCommentHint(AbstractPlatform $platform): bool
    {
        return true;
    }
}
