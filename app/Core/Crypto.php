<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// Crypto — symmetric encryption (AES-256-GCM via ext-openssl) for sensitive
// values in the database that must be recoverable (e.g. smtp_pass), unlike
// user passwords — so a one-way hash doesn't work here.
// ext-openssl was chosen over libsodium because it's almost always enabled
// on shared hosting (a TLS/cURL requirement), unlike sodium which may be
// disabled on shared hosts.
// The key is kept only in config.php (outside the database).
// ═══════════════════════════════════════════════════════════

class Crypto
{
    private const PREFIX = 'v1:';
    private const CIPHER  = 'aes-256-gcm';
    private const TAG_LEN = 16;

    private static ?string $key = null;

    /** Initializes with a base64 key from config.php. Missing key/extension -> disabled (safe passthrough). */
    public static function init(string $base64Key): void
    {
        self::$key = null;
        // trim: avoids decode failures from a stray newline/space when copying
        // the key-generation command's output into config.php
        $base64Key = trim($base64Key);
        if ($base64Key === ''
            || !function_exists('openssl_encrypt')
            || !in_array(self::CIPHER, openssl_get_cipher_methods(), true)) {
            return;
        }
        $key = base64_decode($base64Key, true);
        if ($key !== false && strlen($key) === 32) {
            self::$key = $key;
        }
    }

    /** Encrypts. Returns the value unchanged if no key is set or the value is empty. */
    public static function encrypt(string $plain): string
    {
        if (self::$key === null || $plain === '') {
            return $plain;
        }
        $iv  = random_bytes(openssl_cipher_iv_length(self::CIPHER));
        $tag = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, self::$key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            return $plain;
        }
        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    /**
     * Decrypts. Values without the v1: prefix (legacy plaintext data) are
     * returned unchanged so existing installs keep working without a migration.
     * If no key is available or authentication fails, returns an empty string (fail-safe).
     */
    public static function decrypt(string $stored): string
    {
        if ($stored === '' || strpos($stored, self::PREFIX) !== 0) {
            return $stored;
        }
        if (self::$key === null) {
            return '';
        }
        $raw   = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        $ivLen = openssl_cipher_iv_length(self::CIPHER);
        if ($raw === false || strlen($raw) <= $ivLen + self::TAG_LEN) {
            return '';
        }
        $iv     = substr($raw, 0, $ivLen);
        $tag    = substr($raw, $ivLen, self::TAG_LEN);
        $cipher = substr($raw, $ivLen + self::TAG_LEN);
        $plain  = openssl_decrypt($cipher, self::CIPHER, self::$key, OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? '' : $plain;
    }
}
