<?php

namespace App\Service;

use function sodium_crypto_secretbox;
use function sodium_crypto_secretbox_open;
use function sodium_crypto_generichash;
use function sodium_bin2hex;

class CryptoService
{
    private readonly string $key;

    public function __construct(
        private readonly string $encryptionKey,
    ) {
        try {
            $key = hex2bin($encryptionKey);
        } catch (\Exception) {
            $key = $encryptionKey;
        }

        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            $key = sodium_crypto_generichash($key, length: SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        }

        $this->key = $key;
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

        return sodium_bin2hex($nonce . $ciphertext);
    }

    public function decrypt(string $ciphertextHex): ?string
    {
        try {
            $raw = hex2bin($ciphertextHex);
        } catch (\Exception) {
            return null;
        }

        if (strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);

        if ($plaintext === false) {
            return null;
        }

        return $plaintext;
    }

    /**
     * Chiffre une chaîne en token URL-safe (Base64URL sans padding).
     */
    public function encryptUrlToken(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

        return rtrim(strtr(base64_encode($nonce . $ciphertext), '+/', '-_'), '=');
    }

    /**
     * Déchiffre un token URL-safe. Retourne null si altéré ou invalide.
     */
    public function decryptUrlToken(string $token): ?string
    {
        $raw = base64_decode(strtr($token, '-_', '+/'), true);
        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);

        return $plaintext === false ? null : $plaintext;
    }

    /**
     * Chiffre un ID d'entité numérique pour l'exposer de manière opaque dans une URL.
     */
    public function encryptId(int $id, string $context = 'default'): string
    {
        return $this->encryptUrlToken($context . ':' . $id);
    }

    /**
     * Déchiffre un ID d'URL opaque et valide le contexte.
     */
    public function decryptId(string $token, string $context = 'default'): ?int
    {
        $payload = $this->decryptUrlToken($token);
        if ($payload === null) {
            return null;
        }

        $parts = explode(':', $payload, 2);
        if (count($parts) !== 2 || $parts[0] !== $context || !ctype_digit($parts[1])) {
            return null;
        }

        return (int) $parts[1];
    }

    /**
     * Génère un token d'URL signée cryptographiquement avec expiration (TTL).
     */
    public function generateSignedToken(int $id, int $ttlSeconds = 3600, string $context = 'download'): string
    {
        $expiresAt = time() + $ttlSeconds;
        return $this->encryptUrlToken($context . ':' . $id . ':' . $expiresAt);
    }

    /**
     * Vérifie et extrait l'ID d'un token d'URL signée. Retourne null si expiré ou altéré.
     */
    public function verifySignedToken(string $token, string $context = 'download'): ?int
    {
        $payload = $this->decryptUrlToken($token);
        if ($payload === null) {
            return null;
        }

        $parts = explode(':', $payload, 3);
        if (count($parts) !== 3 || $parts[0] !== $context || !ctype_digit($parts[1]) || !ctype_digit($parts[2])) {
            return null;
        }

        $expiresAt = (int) $parts[2];
        if (time() > $expiresAt) {
            return null; // Expiré
        }

        return (int) $parts[1];
    }

    public static function generateKey(): string
    {
        return sodium_bin2hex(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }
}
