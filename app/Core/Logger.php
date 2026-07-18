<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// Logger — persists application errors/warnings to the error_logs table
// (replaces error_log(), which wrote somewhere the app could never read back).
// Defensive by design: a failure while logging must never mask the original
// error or loop back into itself, so any DB failure here falls back to
// PHP's plain error_log() instead of throwing.
// ═══════════════════════════════════════════════════════════

class Logger
{
    public static function error(string $message, array $context = []): void
    {
        self::log('error', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::log('warning', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::log('info', $message, $context);
    }

    public static function debug(string $message, array $context = []): void
    {
        self::log('debug', $message, $context);
    }

    private static function log(string $level, string $message, array $context): void
    {
        $context += [
            'request_uri'    => $_SERVER['REQUEST_URI']    ?? null,
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? null,
            'user_id'        => class_exists('UserSession') ? (UserSession::id() ?: null) : null,
            'ip'             => $_SERVER['REMOTE_ADDR']     ?? null,
        ];

        try {
            DB::run(
                'INSERT INTO error_logs (level, message, context, created_at) VALUES (:level, :message, :context, NOW())',
                [
                    ':level'   => $level,
                    ':message' => $message,
                    ':context' => json_encode($context, JSON_UNESCAPED_UNICODE),
                ]
            );
        } catch (\Throwable $e) {
            error_log("[Logger fallback] {$level}: {$message}");
        }
    }
}
