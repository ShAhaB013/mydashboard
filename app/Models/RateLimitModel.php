<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// RateLimitModel — خواندن/مدیریت رکوردهای محدودیت تلاش ورود
//   جدول login_rate_limit (کلید ترکیبی ip + scope)
//   scope: 'user' = ورود کاربرها (api.php) ، 'admin' = پنل
// ═══════════════════════════════════════════════════════════

class RateLimitModel
{
    /**
     * همه رکوردها به‌همراه وضعیت بلاک و زمان باقیمانده.
     * مرتب‌سازی: بلاک‌های فعال بالا، سپس جدیدترین تلاش.
     */
    public function all(): array
    {
        $rows = DB::run(
            'SELECT ip, scope, attempts, last_attempt, blocked_until
             FROM login_rate_limit
             ORDER BY (blocked_until > UNIX_TIMESTAMP()) DESC, last_attempt DESC'
        )->fetchAll();

        $now = time();
        foreach ($rows as &$r) {
            $r['attempts']      = (int) $r['attempts'];
            $r['last_attempt']  = (int) $r['last_attempt'];
            $r['blocked_until'] = (int) $r['blocked_until'];
            $r['is_blocked']    = $r['blocked_until'] > $now;
            $r['remaining']     = $r['is_blocked'] ? ($r['blocked_until'] - $now) : 0;
        }
        unset($r);

        return $rows;
    }

    /** رفع انسداد دستی یک IP در یک scope (حذف کامل رکورد → شمارنده صفر می‌شود) */
    public function unblock(string $ip, string $scope): bool
    {
        $stmt = DB::run(
            'DELETE FROM login_rate_limit WHERE ip = :ip AND scope = :scope',
            [':ip' => $ip, ':scope' => $scope]
        );
        return $stmt->rowCount() > 0;
    }

    /** پاک‌سازی همه رکوردهای منقضی (بدون بلاک فعال) */
    public function clearInactive(): int
    {
        $stmt = DB::run(
            'DELETE FROM login_rate_limit WHERE blocked_until <= UNIX_TIMESTAMP()'
        );
        return $stmt->rowCount();
    }
}
