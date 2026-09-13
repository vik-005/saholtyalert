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

    public static function generateKey(): string
    {
        return sodium_bin2hex(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }
}
