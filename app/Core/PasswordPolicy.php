<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// PasswordPolicy — منبع واحد حقیقت برای قوانین رمز عبور
// قوانین صریح (همگی الزامی) — دقیقا با چک‌لیست زنده‌ی سمت کلاینت یکسان:
//   • طول بین MIN_LENGTH و MAX_LENGTH
//   • حداقل یک حرف کوچک انگلیسی
//   • حداقل یک حرف بزرگ انگلیسی
//   • حداقل یک عدد
//   • حداقل یک نماد (غیر از حرف/عدد انگلیسی)
// ═══════════════════════════════════════════════════════════

class PasswordPolicy
{
    /** حداقل طول مجاز (کف امنیتی) */
    public const MIN_LENGTH = 10;

    /** حداکثر طول مجاز (bcrypt هم عملا تا ~۷۲ بایت را لحاظ می‌کند) */
    public const MAX_LENGTH = 64;

    /** آیا رمز همه‌ی قوانین را برآورده می‌کند؟ (باید با نسخه JS یکسان بماند) */
    public static function isAcceptable(string $pw): bool
    {
        $len = mb_strlen($pw);
        return $len >= self::MIN_LENGTH
            && $len <= self::MAX_LENGTH
            && preg_match('/[a-z]/', $pw)
            && preg_match('/[A-Z]/', $pw)
            && preg_match('/[0-9]/', $pw)
            && preg_match('/[^A-Za-z0-9]/', $pw);
    }

    /** پیام خطای استاندارد برای رمز نامعتبر */
    public static function errorMessage(): string
    {
        return 'رمز عبور باید بین ۱۰ تا ۶۴ کاراکتر و شامل حروف کوچک و بزرگ انگلیسی، عدد و نماد باشد.';
    }
}
