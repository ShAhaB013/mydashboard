<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// ErrorPage — renders the themed HTML error page (403/404/500/503) shown
// to browsers. Deliberately self-contained: no DB, no session, no other
// app class — only static assets (style.css/theme.js/fonts) — so it still
// renders correctly during a total outage (missing config.php, dead DB
// connection, …), which is exactly when it matters most.
// ═══════════════════════════════════════════════════════════
final class ErrorPage
{
    private const DEFAULTS = [
        403 => ['title' => 'دسترسی غیرمجاز', 'desc' => 'اجازهٔ دسترسی به این بخش را ندارید.'],
        404 => ['title' => 'صفحه پیدا نشد', 'desc' => 'صفحه‌ای که دنبالش بودید وجود ندارد یا جابه‌جا شده است.'],
        500 => ['title' => 'خطای داخلی سرور', 'desc' => 'مشکلی در سرور رخ داد. لطفاً کمی بعد دوباره تلاش کنید.'],
        503 => ['title' => 'سرویس در دسترس نیست', 'desc' => 'سرور موقتاً قادر به پاسخ‌گویی نیست. لطفاً کمی بعد دوباره تلاش کنید.'],
    ];

    // Small labels above/below the digit inside each circle (right/middle/left
    // visually — array order follows the digit order of the code).
    private const LABELS = [
        403 => [['اوه!', 'متاسفیم'], ['دسترسی', 'غیرمجاز'], ['اجازه', 'ندارید']],
        404 => [['اوه!', 'متاسفیم'], ['صفحه', 'پیدا نشد'], ['چیزی', 'اینجا نیست']],
        500 => [['اوه!', 'متاسفیم'], ['خطای', 'سرور'], ['مشکلی', 'پیش آمد']],
        503 => [['اوه!', 'متاسفیم'], ['سرویس', 'در دسترس نیست'], ['کمی بعد', 'تلاش کنید']],
    ];

    public static function render(int $code, ?string $title = null, ?string $desc = null, ?string $debugDetail = null): void
    {
        $fallback = self::DEFAULTS[$code] ?? self::DEFAULTS[500];
        $title  = $title !== null && $title !== '' ? $title : $fallback['title'];
        $desc   = $desc  !== null && $desc  !== '' ? $desc  : $fallback['desc'];
        $digits = str_split((string) $code);
        $labels = self::LABELS[$code] ?? self::LABELS[500];

        if (!headers_sent()) {
            http_response_code($code);
            header('Content-Type: text/html; charset=utf-8');
        }

        require __DIR__ . '/../Views/error_page.php';
    }

    public static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}
