<?php
// ═══════════════════════════════════════════════════════════
// Response — sends JSON responses to the client
// ═══════════════════════════════════════════════════════════

class Response
{
    /** Success response */
    public static function ok(array $extra = []): void
    {
        self::send(array_merge(['ok' => true], $extra));
    }

    /** Error response */
    public static function error(string $message): void
    {
        self::send(['ok' => false, 'msg' => $message]);
    }

    /** Sends the JSON response and ends execution */
    private static function send(array $data): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
