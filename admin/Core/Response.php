<?php
// ═══════════════════════════════════════════════════════════
// Response — ارسال پاسخ‌های JSON به کلاینت
// ═══════════════════════════════════════════════════════════

class Response
{
    /** پاسخ موفق */
    public static function ok(array $extra = []): void
    {
        self::send(array_merge(['ok' => true], $extra));
    }

    /** پاسخ خطا */
    public static function error(string $message): void
    {
        self::send(['ok' => false, 'msg' => $message]);
    }

    /** ارسال پاسخ JSON و پایان اجرا */
    private static function send(array $data): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
