<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// UserSession — مدیریت session کاربران عادی
// کاملا مجزا از session ادمین (session_name متفاوت)
// ═══════════════════════════════════════════════════════════

class UserSession
{
    private const SESSION_NAME = 'dash_user';

    /** طول عمر پیش‌فرض نشست (ساعت) اگر تنظیمات در دسترس نباشد */
    private const TTL_HOURS_DEFAULT = 24;

    public static function start(): void
    {
        if (session_status() !== PHP_SESSION_NONE) return;

        // ── ذخیره‌سازی نشست در دیتابیس (به‌جای فایل) ──
        // نیازمند اتصال برقرار DB است؛ همه نقاط ورود پیش از این، bootstrap.php
        // را بارگذاری می‌کنند (autoload + DB::connect + همین start).
        // مدت فعال‌بودن نشست از پنل ادمین قابل تنظیم است (۱ تا ۷۲۰ ساعت).
        $ttl = SettingsModel::getInt('session_ttl_hours', 1, 720, self::TTL_HOURS_DEFAULT) * 3600;
        ini_set('session.gc_maxlifetime', (string) $ttl);
        ini_set('session.use_strict_mode', '1'); // شناسه نامعتبر پذیرفته نشود
        session_set_save_handler(new DbSessionHandler($ttl), true);

        session_name(self::SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => $ttl,
            'path'     => '/',
            'secure'   => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }

    /**
     * تشخیص مقاومِ HTTPS. `isset($_SERVER['HTTPS'])` نادرست بود: برخی سرورها
     * روی HTTP مقدار 'off' می‌گذارند (→ کوکی اشتباهاً Secure) و پشت TLS-terminating
     * proxy اصلاً ست نمی‌شود (→ کوکی Secure نمی‌شود). این متد هر سه سیگنالِ
     * معتبر را بررسی می‌کند.
     */
    private static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
            return true;
        }
        return ((string) ($_SERVER['SERVER_PORT'] ?? '')) === '443';
    }

    public static function check(): bool
    {
        return !empty($_SESSION['user_id']);
    }

    /**
     * توکن CSRFِ نشست را تضمین می‌کند (اگر نبود می‌سازد) و برمی‌گرداند.
     * منبع یگانه — به‌جای تکرارِ همین بلوک در نقاط ورود مختلف.
     */
    public static function ensureCsrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function id(): int
    {
        return (int) ($_SESSION['user_id'] ?? 0);
    }

    public static function displayName(): string
    {
        return $_SESSION['display_name'] ?? $_SESSION['username'] ?? '';
    }

    /** سطح دسترسی ذخیره‌شده در سشن (نمایشی — مرجع امنیتی نیست) */
    public static function role(): string
    {
        return $_SESSION['role'] ?? 'user';
    }

    public static function isAdmin(): bool
    {
        return self::role() === 'admin';
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(
                session_name(), '', time() - 42000,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']
            );
        }
        session_destroy();
    }
}