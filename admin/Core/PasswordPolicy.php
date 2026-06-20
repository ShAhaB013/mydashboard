<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// PasswordPolicy — منبع واحد حقیقت برای قدرت رمز عبور
// امتیاز ۰..۴ دقیقا با checkStrength سمت کلاینت یکسان است:
//   score = (len>=8) + (hasUpper) + (hasDigit) + (hasSpecial)
//   برچسب‌ها: ['', ضعیف, متوسط, خوب, قوی]
// سیاست: «حداقل متوسط» → score >= 2 و حداقل ۶ کاراکتر.
// ═══════════════════════════════════════════════════════════

class PasswordPolicy
{
    /** حداقل طول مجاز (کف امنیتی) */
    public const MIN_LENGTH = 6;

    /** حداقل امتیاز قابل قبول: ۲ = «متوسط» */
    public const MIN_SCORE = 2;

    /** امتیاز قدرت رمز (۰ تا ۴) — باید با نسخه JS یکسان بماند */
    public static function score(string $pw): int
    {
        $s = 0;
        if (mb_strlen($pw) >= 8)                $s++;
        if (preg_match('/[A-Z]/', $pw))         $s++;
        if (preg_match('/[0-9]/', $pw))         $s++;
        if (preg_match('/[^A-Za-z0-9]/', $pw))  $s++;
        return $s;
    }

    /** آیا رمز حداقل در سطح «متوسط» است؟ */
    public static function isAcceptable(string $pw): bool
    {
        return mb_strlen($pw) >= self::MIN_LENGTH && self::score($pw) >= self::MIN_SCORE;
    }

    /** پیام خطای استاندارد برای رمز ضعیف */
    public static function errorMessage(): string
    {
        return 'رمز عبور باید حداقل در سطح «متوسط» باشد: دست‌کم ۶ کاراکتر همراه با ترکیبی از حروف بزرگ، عدد یا نماد.';
    }
}
