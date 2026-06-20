<?php
declare(strict_types=1);

class RateLimiter
{
    private const MAX_ATTEMPTS    = 10;   // حداکثر تلاش مجاز
    private const WINDOW_SECONDS  = 900;  // پنجره زمانی: ۱۵ دقیقه
    private const BLOCK_SECONDS   = 900;  // مدت بلاک: ۱۵ دقیقه
    private const CLEANUP_CHANCE  = 50;   // هر ۱ در X درخواست، cleanup اجرا می‌شه

    private string $ip;
    private string $scope;

    /**
     * @param string $scope جداسازی شمارنده‌ها: 'user' برای api.php و 'admin' برای admin.php
     *                      تا قفل‌شدن یکی روی دیگری اثر نگذارد.
     */
    public function __construct(string $scope = 'user')
    {
        $this->ip    = $this->resolveIp();
        $this->scope = ($scope === 'admin') ? 'admin' : 'user';
    }

    public function isBanned(): bool
    {
        $row = $this->fetchRow();
        if (!$row) return false;

        // اگه بلاک فعال باشه
        if ($row['blocked_until'] > time()) return true;

        // اگه بلاک منقضی شده، ریست کن
        if ($row['blocked_until'] > 0 && $row['blocked_until'] <= time()) {
            $this->reset();
            return false;
        }

        return false;
    }

    /**
     * ثبت یک تلاش ناموفق
     */
    public function recordFailure(): void
    {
        $now = time();
        $row = $this->fetchRow();

        if (!$row) {
            // اولین تلاش
            DB::run(
                'INSERT INTO login_rate_limit (ip, scope, attempts, last_attempt, blocked_until)
                 VALUES (:ip, :scope, 1, :now, 0)',
                [':ip' => $this->ip, ':scope' => $this->scope, ':now' => $now]
            );
            return;
        }

        // اگه پنجره زمانی منقضی شده، از نو شروع کن
        if ($now - $row['last_attempt'] > self::WINDOW_SECONDS) {
            DB::run(
                'UPDATE login_rate_limit
                 SET attempts = 1, last_attempt = :now, blocked_until = 0
                 WHERE ip = :ip AND scope = :scope',
                [':now' => $now, ':ip' => $this->ip, ':scope' => $this->scope]
            );
            return;
        }

        $newAttempts = $row['attempts'] + 1;
        $blockedUntil = $newAttempts >= self::MAX_ATTEMPTS
            ? $now + self::BLOCK_SECONDS
            : 0;

        DB::run(
            'UPDATE login_rate_limit
             SET attempts = :att, last_attempt = :now, blocked_until = :blocked
             WHERE ip = :ip AND scope = :scope',
            [
                ':att'     => $newAttempts,
                ':now'     => $now,
                ':blocked' => $blockedUntil,
                ':ip'      => $this->ip,
                ':scope'   => $this->scope,
            ]
        );

        // پاک‌سازی تصادفی رکوردهای قدیمی
        if (random_int(1, self::CLEANUP_CHANCE) === 1) {
            $this->cleanup();
        }
    }

    /**
     * ریست کردن بعد از لاگین موفق
     */
    public function reset(): void
    {
        DB::run(
            'DELETE FROM login_rate_limit WHERE ip = :ip AND scope = :scope',
            [':ip' => $this->ip, ':scope' => $this->scope]
        );
    }

    /**
     * چند ثانیه تا رفع بلاک باقی مانده
     */
    public function secondsUntilUnblock(): int
    {
        $row = $this->fetchRow();
        if (!$row || $row['blocked_until'] <= time()) return 0;
        return $row['blocked_until'] - time();
    }

    // ── Private ──────────────────────────────────────────────

    private function fetchRow(): ?array
    {
        $row = DB::run(
            'SELECT * FROM login_rate_limit WHERE ip = :ip AND scope = :scope',
            [':ip' => $this->ip, ':scope' => $this->scope]
        )->fetch();

        return $row ?: null;
    }

    private function cleanup(): void
    {
        DB::run(
            'DELETE FROM login_rate_limit WHERE last_attempt < :cutoff',
            [':cutoff' => time() - 86400]
        );
    }

    private function resolveIp(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // فقط اگه سرور پشت proxy شناخته‌شده بود این رو فعال کن
        // در غیر این صورت X-Forwarded-For قابل جعل است
        // if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        //     $forwarded = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        //     $ip = trim($forwarded[0]);
        // }

        // اعتبارسنجی فرمت IP
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }

        return '0.0.0.0';
    }
}