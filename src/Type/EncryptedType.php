<?php

namespace App\Type;

use App\Service\CryptoService;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\StringType;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class EncryptedType extends StringType
{
    public const NAME = 'encrypted_string';

    private static ?CryptoService $cryptoService = null;
    private static bool $cryptoServiceFailed = false;
    private static LoggerInterface $logger;

    public static function setLogger(LoggerInterface $logger): void
    {
        self::$logger = $logger;
    }

    private static function getLogger(): LoggerInterface
    {
        return self::$logger ?? (self::$logger = new NullLogger());
    }

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

        try {
            $decrypted = $this->getCryptoService()->decrypt((string) $value);
        } catch (\Throwable $e) {
            // Si le service de déchiffrement n'est pas disponible (clé absente, etc.),
            // on retourne null plutôt que de lever une exception.
            // Lever une exception ici déconnecte l'utilisateur car Symfony
            // l'attrape dans le UserProvider et invalide la session.
            self::getLogger()->warning('EncryptedType: déchiffrement impossible (service indisponible). Vérifier APP_ENCRYPTION_KEY.', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        // Si le déchiffrement échoue (mauvaise clé, données corrompues), on retourne null
        // plutôt que de propager une exception qui invaliderait la session.
        // Le champ totpSecret à null désactive simplement le 2FA pour cet utilisateur.
        if ($decrypted === null) {
            self::getLogger()->warning('EncryptedType: déchiffrement retourné null pour une valeur non-vide. La clé APP_ENCRYPTION_KEY a peut-être changé.');
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
