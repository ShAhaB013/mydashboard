<?php
// ═══════════════════════════════════════════════════════════
// Validator — اعتبارسنجی ورودی‌ها
// ═══════════════════════════════════════════════════════════

class Validator
{
    /**
     * مسیر ابزار باید:
     * - خالی نباشد
     * - حاوی javascript: / data: / .. نباشد
     * - فقط از کاراکترهای مجاز تشکیل شده باشد
     */
    public static function isValidPath(string $path): bool
    {
        if (empty($path)) {
            return false;
        }

        if (preg_match('/^(javascript:|data:|vbscript:|blob:)/i', $path)) {
            return false;
        }

        if (strpos($path, '..') !== false) {
            return false;
        }

        // پشتیبانی از لینک‌های خارجی (http / https)
        if (preg_match('/^https?:\/\/.+/i', $path)) {
            return (bool) filter_var($path, FILTER_VALIDATE_URL);
        }

        return (bool) preg_match('/^(\/[\w\-\.\/]*|[\w\-][\w\-\.\/]*)$/', $path);
    }

    /**
     * کلید آیکون/انیمیشن باید:
     * - با حرف انگلیسی شروع شود
     * - فقط از حروف، اعداد، خط تیره و underscore تشکیل شده باشد
     * - حداکثر ۴۰ کاراکتر باشد
     */
    public static function isValidKey(string $key): bool
    {
        return (bool) preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,39}$/', $key);
    }

    /**
     * اعتبارسنجی نام/نام خانوادگی: ۲ تا ۶۰ کاراکتر، فقط حروف (فارسی/انگلیسی)
     * به‌همراه فاصله، خط تیره و آپاستروف؛ بدون رقم یا نماد.
     * منبع یگانه — مشترک بین ثبت‌نام عمومی (api.php) و افزودن/ویرایش ادمین (UserController).
     * @return string پیام خطا، یا '' در صورت معتبر بودن.
     */
    public static function name(string $name, string $label): string
    {
        $len = mb_strlen($name);
        if ($len < 2 || $len > 60) {
            return "$label باید بین ۲ تا ۶۰ کاراکتر باشد";
        }
        // حروف یونیکد (شامل فارسی) + فاصله/خط‌تیره/آپاستروف
        if (!preg_match("/^[\p{L}\p{M}][\p{L}\p{M}\s'’\-]*$/u", $name)) {
            return "$label فقط می‌تواند شامل حروف باشد";
        }
        return '';
    }
}
