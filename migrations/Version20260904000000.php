<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260904000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Chiffre les secrets TOTP existants au repos (AES-256 via sodium_crypto_secretbox). Ne touche que les valeurs en clair.';
    }

    public function up(Schema $schema): void
    {
        $connection = $this->connection;
        
        // Vérifier si la colonne totpSecret existe
        $columns = $connection->fetchAllAssociative('SHOW COLUMNS FROM `user` WHERE Field = ?', ['totpSecret']);
        if (empty($columns)) {
            $this->write('<comment>La colonne totpSecret n\'existe pas encore dans la table user. Skip de la migration.</comment>');
            return;
        }
        
        $rows = $connection->fetchAllAssociative(
            'SELECT id, totpSecret FROM `user` WHERE totpSecret IS NOT NULL AND totpSecret != \'\''
        );

        $encryptionKeyHex = $_ENV['APP_ENCRYPTION_KEY'] ?? getenv('APP_ENCRYPTION_KEY');
        if (!$encryptionKeyHex) {
            throw new \RuntimeException('APP_ENCRYPTION_KEY environment variable is required for this migration.');
        }

        $key = @hex2bin($encryptionKeyHex);

        if ($key === false) {
            $key = $encryptionKeyHex;
        }

        if (mb_strlen($key, '8bit') !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            $key = sodium_crypto_generichash($key, length: SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        }

        foreach ($rows as $row) {
            $existing = $row['totpSecret'];
            if ($existing === null || $existing === '') {
                continue;
            }

            // Skip if already encrypted (hex-encoded nonce + ciphertext)
            if ($this->isAlreadyEncrypted($existing, $key)) {
                continue;
            }

            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $ciphertext = sodium_crypto_secretbox($existing, $nonce, $key);
            $encrypted = sodium_bin2hex($nonce . $ciphertext);

            $connection->update(
                '`user`',
                ['totpSecret' => $encrypted],
                ['id' => $row['id']]
            );

            $this->output->writeln(sprintf('  -> TOTP secret chiffré pour l\'utilisateur ID %d', $row['id']));
        }
    }

    public function down(Schema $schema): void
    {
        // SECURITY: No down migration — encrypted secrets cannot be safely decrypted
        // without the key, and reverting would leave plaintext secrets exposed.
        $this->addSql('SELECT 1');
    }

    private function isAlreadyEncrypted(string $value, string $key): bool
    {
        $raw = @hex2bin($value);
        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return false;
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return sodium_crypto_secretbox_open($ciphertext, $nonce, $key) !== false;
    }
}
