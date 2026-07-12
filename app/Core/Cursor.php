<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// Cursor — safe encode/decode of keyset-pagination cursors based on the
// (created_at, id) pair — for adjacent Prev/Next navigation in large lists
// (without the cost of OFFSET at large depths).
// ═══════════════════════════════════════════════════════════

class Cursor
{
    public static function encode(string $createdAt, int $id): string
    {
        return rtrim(strtr(base64_encode("{$createdAt}|{$id}"), '+/', '-_'), '=');
    }

    /**
     * Conservative decoding: any invalid/tampered input simply returns null
     * (i.e. "no cursor" / first page) — it never reaches a raw query.
     * @return array{created_at:string,id:int}|null
     */
    public static function decode(string $cursor): ?array
    {
        $cursor = trim($cursor);
        if ($cursor === '') return null;
        $padded = $cursor . str_repeat('=', (4 - strlen($cursor) % 4) % 4);
        $raw = base64_decode(strtr($padded, '-_', '+/'), true);
        if ($raw === false) return null;
        if (!preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\|(\d+)$/', $raw, $m)) return null;
        return ['created_at' => $m[1], 'id' => (int) $m[2]];
    }
}
