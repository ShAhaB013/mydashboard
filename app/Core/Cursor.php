<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// Cursor — کدگذاری/کدگشایی امنِ cursor صفحه‌بندی keyset بر پایه‌ی
// زوج (created_at, id) — برای پیمایش Prev/Next مجاور در لیست‌های بزرگ
// (بدون هزینه‌ی OFFSET در عمق‌های زیاد).
// ═══════════════════════════════════════════════════════════

class Cursor
{
    public static function encode(string $createdAt, int $id): string
    {
        return rtrim(strtr(base64_encode("{$createdAt}|{$id}"), '+/', '-_'), '=');
    }

    /**
     * کدگشایی محافظه‌کارانه: هر ورودی نامعتبر/دستکاری‌شده به‌سادگی null برمی‌گرداند
     * (یعنی «بدون cursor» / صفحه‌ی اول) — هرگز به کوئری خام نمی‌رسد.
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
