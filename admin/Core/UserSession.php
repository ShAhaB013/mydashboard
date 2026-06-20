<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// UserSession — مدیریت session کاربران عادی
// کاملا مجزا از session ادمین (session_name متفاوت)
// ═══════════════════════════════════════════════════════════

class UserSession
{
    private const SESSION_NAME = 'dash_user';

    public static function start(): void
    {
        if (session_status() !== PHP_SESSION_NONE) return;

        session_name(self::SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }

    public static function check(): bool
    {
        return !empty($_SESSION['user_id']);
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