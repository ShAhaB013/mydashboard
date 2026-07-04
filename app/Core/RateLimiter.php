<?php
declare(strict_types=1);

class RateLimiter
{
    private const WINDOW_SECONDS  = 900;  // پنجره زمانی: ۱۵ دقیقه
    private const BLOCK_SECONDS   = 900;  // مدت بلاک: ۱۵ دقیقه
    private const CLEANUP_CHANCE  = 50;   // هر ۱ در X درخواست، cleanup اجرا می‌شه

    /** اسکوپ‌های مبتنی بر حساب (per-account) — از لیست IPِ پنل مخفی می‌مانند */
    public const ACCT_USER  = 'acct:user';
    public const ACCT_ADMIN = 'acct:admin';

    private string $ip;
    private string $scope;
    private int    $maxAttempts;

    /**
     * @param string      $scope جداسازی شمارنده‌ها. مقادیر IP-محور: 'user' (api.php)
     *                    و 'admin' (admin.php). مقادیر حساب‌محور: ACCT_USER / ACCT_ADMIN.
     * @param string|null $key   کلیدِ شمارنده که در ستون `ip` ذخیره می‌شود. اگر null
     *                    باشد، IPِ واقعیِ کلاینت استفاده می‌شود (اسکوپ‌های IP-محور).
     *                    برای اسکوپ‌های حساب‌محور، هشِ نام‌کاربری پاس داده می‌شود.
     * @param int         $maxAttempts سقف تلاش تا بلاک (پیش‌فرض ۱۰). برای اسکوپ حساب
     *                    عمداً بالاتر (مثلاً ۲۰) داده می‌شود تا کاربر عادی قفل نشود و
     *                    فقط حمله‌ی متمرکز روی یک حساب را بگیرد (کاهش ریسکِ lockout-DoS).
     */
    public function __construct(string $scope = 'user', ?string $key = null, int $maxAttempts = 10)
    {
        $allowed     = ['user', 'admin', self::ACCT_USER, self::ACCT_ADMIN];
        $this->scope = in_array($scope, $allowed, true) ? $scope : 'user';
        $this->ip    = $key !== null ? mb_substr($key, 0, 45) : $this->resolveIp();
        $this->maxAttempts = max(1, $maxAttempts);
    }

    /** کلیدِ حساب‌محور از نام‌کاربری (هش‌شده تا در ستون ۴۵ کاراکتری جا شود) */
    public static function accountKey(string $username): string
    {
        return substr(hash('sha256', mb_strtolower(trim($username))), 0, 45);
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
     * ثبت یک تلاش ناموفق — به‌صورت اتمیک در یک دستور.
     *
     * الگوی قبلی (SELECT سپس UPDATE) شرطِ رقابتی داشت: چند درخواستِ هم‌زمان
     * یک مقدار قدیمی می‌خواندند و شمارنده را کم‌شمار می‌کردند، پس مهاجم می‌توانست
     * از سقف عبور کند. اینجا شمارش و تصمیمِ بلاک هر دو داخلِ موتور دیتابیس و در
     * یک `INSERT ... ON DUPLICATE KEY UPDATE` انجام می‌شود (بدونِ پنجره‌ی رقابت).
     *
     * ترتیب SET مهم است: هر سه عبارت به مقادیرِ «قدیمِ» attempts/last_attempt
     * ارجاع می‌دهند (last_attempt در انتها مقداردهی می‌شود).
     */
    public function recordFailure(): void
    {
        $now = time();
        // placeholderهای یکتا: با EMULATE_PREPARES=false نمی‌توان یک نام را
        // چند بار در کوئری تکرار کرد، پس هر تکرار نامِ جدا و مقدارِ یکسان دارد.
        DB::run(
            'INSERT INTO login_rate_limit (ip, scope, attempts, last_attempt, blocked_until)
             VALUES (:ip, :scope, 1, :now0, 0)
             ON DUPLICATE KEY UPDATE
               blocked_until = IF(
                   IF(:now1 - last_attempt > :win1, 1, attempts + 1) >= :max,
                   :now2 + :block, 0),
               attempts      = IF(:now3 - last_attempt > :win2, 1, attempts + 1),
               last_attempt  = :now4',
            [
                ':ip'    => $this->ip,
                ':scope' => $this->scope,
                ':now0'  => $now, ':now1' => $now, ':now2' => $now, ':now3' => $now, ':now4' => $now,
                ':win1'  => self::WINDOW_SECONDS, ':win2' => self::WINDOW_SECONDS,
                ':max'   => $this->maxAttempts,
                ':block' => self::BLOCK_SECONDS,
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