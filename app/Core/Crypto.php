<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// Crypto — رمزنگاری متقارن (libsodium secretbox) برای مقادیر حساسِ
// قابل‌بازیابی در دیتابیس (مثل smtp_pass) که برخلاف رمز کاربر باید
// دوباره به‌صورت اصلی خوانده شود؛ پس هش یک‌طرفه اینجا کاربرد ندارد.
// کلید فقط در config.php (خارج از دیتابیس) نگه‌داری می‌شود.
// ═══════════════════════════════════════════════════════════

class Crypto
{
    private const PREFIX = 'v1:';

    private static ?string $key = null;

    /** مقداردهی اولیه با کلید base64 از config.php. نبودِ کلید/افزونه → غیرفعال (passthrough امن). */
    public static function init(string $base64Key): void
    {
        self::$key = null;
        if ($base64Key === '' || !function_exists('sodium_crypto_secretbox')) {
            return;
        }
        $key = base64_decode($base64Key, true);
        if ($key !== false && strlen($key) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            self::$key = $key;
        }
    }

    /** رمزنگاری. اگر کلید تنظیم نشده باشد یا مقدار خالی باشد، بدون تغییر برمی‌گردد. */
    public static function encrypt(string $plain): string
    {
        if (self::$key === null || $plain === '') {
            return $plain;
        }
        $nonce  = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plain, $nonce, self::$key);
        return self::PREFIX . base64_encode($nonce . $cipher);
    }

    /**
     * رمزگشایی. مقادیرِ بدونِ پیشوندِ v1: (داده‌های قدیمیِ متن‌ساده) بدون تغییر
     * برگردانده می‌شوند تا نصب‌های موجود بدون migration کار کنند. اگر کلید
     * موجود نباشد یا صحتِ رمز تایید نشود، رشته‌ی خالی برمی‌گردد (fail-safe).
     */
    public static function decrypt(string $stored): string
    {
        if ($stored === '' || strpos($stored, self::PREFIX) !== 0) {
            return $stored;
        }
        if (self::$key === null) {
            return '';
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return '';
        }
        $nonce  = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain  = sodium_crypto_secretbox_open($cipher, $nonce, self::$key);
        return $plain === false ? '' : $plain;
    }
}
