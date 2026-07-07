<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// Crypto — رمزنگاری متقارن (AES-256-GCM از طریق ext-openssl) برای مقادیر
// حساسِ قابل‌بازیابی در دیتابیس (مثل smtp_pass) که برخلاف رمز کاربر باید
// دوباره به‌صورت اصلی خوانده شود؛ پس هش یک‌طرفه اینجا کاربرد ندارد.
// ext-openssl به‌جای libsodium انتخاب شد چون روی هاست‌های اشتراکی
// تقریبا همیشه فعال است (لازمه‌ی TLS/cURL)، برخلاف sodium که ممکن است
// در هاست‌های اشتراکی غیرفعال باشد.
// کلید فقط در config.php (خارج از دیتابیس) نگه‌داری می‌شود.
// ═══════════════════════════════════════════════════════════

class Crypto
{
    private const PREFIX = 'v1:';
    private const CIPHER  = 'aes-256-gcm';
    private const TAG_LEN = 16;

    private static ?string $key = null;

    /** مقداردهی اولیه با کلید base64 از config.php. نبودِ کلید/افزونه → غیرفعال (passthrough امن). */
    public static function init(string $base64Key): void
    {
        self::$key = null;
        // trim: جلوگیری از شکستِ decode به‌خاطر newline/فاصله‌ی اضافی هنگام
        // کپی‌کردنِ خروجیِ دستورِ تولید کلید در config.php
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

    /** رمزنگاری. اگر کلید تنظیم نشده باشد یا مقدار خالی باشد، بدون تغییر برمی‌گردد. */
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
