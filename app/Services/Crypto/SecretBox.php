<?php

namespace App\Services\Crypto;

use RuntimeException;

/**
 * Authenticated encryption for connector secrets using AES-256-GCM.
 *
 * Format: "v1." . base64( iv(12) | tag(16) | ciphertext )
 *
 * The GCM authentication tag guarantees integrity — any tampering with the
 * stored ciphertext makes decryption fail rather than returning garbage.
 * Uses a dedicated 256-bit key (DATA_ENCRYPTION_KEY) so connector secrets can
 * be rotated independently of Laravel's APP_KEY.
 */
class SecretBox
{
    private const VERSION = 'v1.';
    private const CIPHER = 'aes-256-gcm';
    private const IV_LEN = 12;
    private const TAG_LEN = 16;

    private string $key;

    public function __construct(?string $key = null)
    {
        $configured = $key ?? config('security.data_encryption_key');

        if (empty($configured)) {
            throw new RuntimeException('DATA_ENCRYPTION_KEY is not configured.');
        }

        $this->key = $this->normalizeKey($configured);
    }

    /** Accept a "base64:..." 32-byte key, a raw 32-byte key, or derive one from a passphrase. */
    private function normalizeKey(string $key): string
    {
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            if ($decoded !== false) {
                $key = $decoded;
            }
        }

        if (strlen($key) === 32) {
            return $key;
        }

        // Derive a deterministic 256-bit key from an arbitrary-length passphrase.
        return hash('sha256', $key, true);
    }

    public function encrypt(string $plaintext, ?string $key = null): string
    {
        $k = $key !== null ? $this->normalizeKey($key) : $this->key;
        $iv = random_bytes(self::IV_LEN);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext, self::CIPHER, $k, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LEN
        );

        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed.');
        }

        return self::VERSION.base64_encode($iv.$tag.$ciphertext);
    }

    public function decrypt(string $payload, ?string $key = null): string
    {
        $k = $key !== null ? $this->normalizeKey($key) : $this->key;

        if (! str_starts_with($payload, self::VERSION)) {
            throw new RuntimeException('Unsupported ciphertext format.');
        }

        $raw = base64_decode(substr($payload, strlen(self::VERSION)), true);

        if ($raw === false || strlen($raw) < self::IV_LEN + self::TAG_LEN) {
            throw new RuntimeException('Malformed ciphertext.');
        }

        $iv = substr($raw, 0, self::IV_LEN);
        $tag = substr($raw, self::IV_LEN, self::TAG_LEN);
        $ciphertext = substr($raw, self::IV_LEN + self::TAG_LEN);

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $k, OPENSSL_RAW_DATA, $iv, $tag);

        if ($plaintext === false) {
            throw new RuntimeException('Decryption failed (wrong key or tampered data).');
        }

        return $plaintext;
    }
}
