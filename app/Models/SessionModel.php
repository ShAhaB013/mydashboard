<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// SessionModel — پرس‌وجوی نشست‌های فعال (جدول `sessions`) برای مدیریت ادمین.
// (ذخیره/خواندن خود نشست در DbSessionHandler انجام می‌شود؛ این مدل فقط
//  برای نمایش و پایان‌دادن نشست‌ها در پنل است.)
// ═══════════════════════════════════════════════════════════

class SessionModel
{
    /** فهرست نشست‌های فعال (به‌همراه نام کاربر). $userId=null → همه کاربران. */
    public static function active(?int $userId = null, int $limit = 300): array
    {
        $sql = 'SELECT s.id, s.user_id, s.ip, s.user_agent, s.last_seen, s.expires_at,
                       u.username, u.display_name, u.role
                  FROM sessions s
                  LEFT JOIN users u ON u.id = s.user_id
                 WHERE s.expires_at > :now';
        $params = [':now' => time()];
        if ($userId !== null) {
            $sql .= ' AND s.user_id = :uid';
            $params[':uid'] = $userId;
        }
        $sql .= ' ORDER BY s.last_seen DESC LIMIT ' . max(1, (int) $limit);
        try {
            return DB::run($sql, $params)->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** شمارش نشست‌های فعال هر کاربر → [user_id => count] */
    public static function countsByUser(): array
    {
        try {
            $rows = DB::run(
                'SELECT user_id, COUNT(*) AS c FROM sessions
                  WHERE expires_at > :now AND user_id IS NOT NULL
                  GROUP BY user_id',
                [':now' => time()]
            )->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['user_id']] = (int) $r['c'];
        }
        return $out;
    }

    /** پایان‌دادن به یک نشست مشخص (با شناسه) */
    public static function terminate(string $id): bool
    {
        try {
            DB::run('DELETE FROM sessions WHERE id = :id', [':id' => $id]);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** پایان همه نشست‌های یک کاربر (اختیاری: به‌جز نشست استثناشده) → تعداد حذف‌شده */
    public static function terminateUser(int $userId, ?string $exceptId = null): int
    {
        $sql    = 'DELETE FROM sessions WHERE user_id = :uid';
        $params = [':uid' => $userId];
        if ($exceptId !== null && $exceptId !== '') {
            $sql .= ' AND id <> :ex';
            $params[':ex'] = $exceptId;
        }
        try {
            return DB::run($sql, $params)->rowCount();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** پایان همه نشست‌ها به‌جز نشست جاری → تعداد حذف‌شده */
    public static function terminateOthers(string $exceptId): int
    {
        try {
            return DB::run('DELETE FROM sessions WHERE id <> :ex', [':ex' => $exceptId])->rowCount();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** پایان‌دادن به نشست تنها در صورتی که متعلق به همین کاربر باشد (مالکیت سرور-تضمین) */
    public static function terminateOwned(string $id, int $userId): bool
    {
        try {
            DB::run(
                'DELETE FROM sessions WHERE id = :id AND user_id = :uid',
                [':id' => $id, ':uid' => $userId]
            );
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** خلاصه خوانا User-Agent برای نمایش (مرورگر · سیستم‌عامل) */
    public static function describeAgent(string $ua): string
    {
        if ($ua === '') return 'نامشخص';

        $browser = 'مرورگر';
        foreach (['Edg' => 'Edge', 'OPR' => 'Opera', 'Chrome' => 'Chrome', 'Firefox' => 'Firefox', 'Safari' => 'Safari'] as $k => $v) {
            if (stripos($ua, $k) !== false) { $browser = $v; break; }
        }
        $os = '';
        foreach (['Windows' => 'Windows', 'Android' => 'Android', 'iPhone' => 'iOS', 'iPad' => 'iOS', 'Mac' => 'macOS', 'Linux' => 'Linux'] as $k => $v) {
            if (stripos($ua, $k) !== false) { $os = $v; break; }
        }
        return $os !== '' ? "{$browser} · {$os}" : $browser;
    }
}
