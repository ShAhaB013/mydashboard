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

    /** Error response. Pass $field (the input's name/id) when the error applies to one specific form field,
     *  so the client can focus that field instead of just showing a toast. */
    public static function error(string $message, ?string $field = null): void
    {
        $data = ['ok' => false, 'msg' => $message];
        if ($field !== null) $data['field'] = $field;
        self::send($data);
    }

    /** Sends the JSON response and ends execution */
    private static function send(array $data): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
