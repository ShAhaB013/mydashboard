<?php
declare(strict_types=1);

/* ═══════════════════════════════════════════════════════════
   version.php — تنها منبع نسخه پروژه (Single Source of Truth)
   ───────────────────────────────────────────────────────────
   مثل پروژه‌های واقعی: نسخه را فقط همین‌جا تغییر بده.
   بعد از هر بار release، فقط APP_VERSION را بالا ببر.
   فرمت Semantic Versioning:  MAJOR.MINOR.PATCH
   ═══════════════════════════════════════════════════════════ */

if (!defined('APP_VERSION')) {
    define('APP_VERSION', '1.1.0');
}

/* تاریخ build — اختیاری، فقط برای نمایش/دیباگ */
if (!defined('APP_BUILD')) {
    define('APP_BUILD', '2026-06');
}

/**
 * نسخه‌گذاری asset برای cache-busting قطعی.
 * ترکیب نسخه اپ + زمان تغییر فایل: هم با هر release کش می‌شکند،
 * هم اگر فایلی جداگانه ویرایش شد، باز هم کش تازه می‌شود.
 *
 * @param string $absPath مسیر مطلق فایل روی دیسک
 */
function asset_v(string $absPath): string
{
    $m = @filemtime($absPath) ?: 0;
    return APP_VERSION . '.' . $m;
}

/** نمایش نسخه به‌صورت متنی (مثلا برای فوتر) */
function app_version_label(): string
{
    return 'v' . APP_VERSION;
}