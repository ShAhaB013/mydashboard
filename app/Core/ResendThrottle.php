<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// ResendThrottle — محدودسازی سمت‌سرور ارسال کد/کد بازیابی
// ───────────────────────────────────────────────────────────
// چرا: کول‌داون سمت کلاینت با ریلود/بازکردن دوباره صفحه دور زده می‌شد.
// این لایه مبتنی بر session است (کوکی نشست با ریلود حفظ می‌شود)، پس
// «بازکردن دوباره صفحه فراموشی رمز» دیگر محدودیت را صفر نمی‌کند.
// کول‌داون پلکانی (base·2^n با سقف) و هم‌راستا با شمارش سمت کلاینت است.
// همچنین anti-enumeration: مستقل از وجود/عدم کاربر، یکسان رفتار می‌کند.
// ═══════════════════════════════════════════════════════════
class ResendThrottle
{
    private const CAP         = 300;   // سقف کول‌داون: ۵ دقیقه
    private const RESET_AFTER = 1800;  // اگر ۳۰ دقیقه فاصله افتاد، سکانس از نو شروع می‌شود

    /** کلید یکتا برای هر «هدف + ایمیل» در session */
    private static function key(string $purpose, string $email): string
    {
        return '_rt_' . $purpose . '_' . md5(strtolower(trim($email)));
    }

    /**
     * ثانیه‌های باقی‌مانده تا مجاز شدن ارسال بعدی (۰ = همین حالا مجاز است).
     * فقط می‌خواند؛ چیزی را تغییر نمی‌دهد.
     */
    public static function retryAfter(string $purpose, string $email, int $base): int
    {
        $st = $_SESSION[self::key($purpose, $email)] ?? null;
        if (!$st) {
            return 0;
        }
        $gap = time() - (int) ($st['t'] ?? 0);
        if ($gap >= self::RESET_AFTER) {
            return 0; // سکانس قدیمی منقضی شده
        }
        $sends = (int) ($st['n'] ?? 0);
        if ($sends <= 0) {
            return 0;
        }
        // کول‌داون پلکانی: ۳۰ → ۶۰ → ۱۲۰ → … تا سقف
        $required = (int) min(round($base * (2 ** ($sends - 1))), self::CAP);
        $remain   = $required - $gap;
        return $remain > 0 ? $remain : 0;
    }

    /** ثبت یک ارسال انجام‌شده (افزایش شمارنده + زمان) */
    public static function record(string $purpose, string $email): void
    {
        $k     = self::key($purpose, $email);
        $st    = $_SESSION[$k] ?? null;
        $now   = time();
        $sends = ($st && ($now - (int) ($st['t'] ?? 0)) < self::RESET_AFTER) ? (int) ($st['n'] ?? 0) : 0;
        $_SESSION[$k] = ['t' => $now, 'n' => $sends + 1];
    }
}
