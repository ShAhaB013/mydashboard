<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// UserSession — manages regular users' sessions
// Completely separate from the admin session (different session_name)
// ═══════════════════════════════════════════════════════════

class UserSession
{
    private const SESSION_NAME = 'dash_user';

    /** Default session lifetime (hours) if settings aren't available */
    private const TTL_HOURS_DEFAULT = 24;

    public static function start(): void
    {
        if (session_status() !== PHP_SESSION_NONE) return;

        // ── Store sessions in the database (instead of files) ──
        // Requires an established DB connection; every entry point loads
        // bootstrap.php before this (autoload + DB::connect + this start).
        // Session lifetime is configurable from the admin panel (1 to 720 hours).
        $ttl = SettingsModel::getInt('session_ttl_hours', 1, 720, self::TTL_HOURS_DEFAULT) * 3600;
        ini_set('session.gc_maxlifetime', (string) $ttl);
        ini_set('session.use_strict_mode', '1'); // reject invalid session IDs

        // Some hosts disable gc in php.ini (gc_probability=0) and run it via a
        // separate cron job instead — which this project doesn't have.
        // Without this explicit setting, expired rows in the sessions table
        // would never get cleaned up.
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
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
     * Robust HTTPS detection. `isset($_SERVER['HTTPS'])` was wrong: some
     * servers set it to 'off' on HTTP (-> cookie incorrectly marked Secure),
     * and behind a TLS-terminating proxy it isn't set at all (-> cookie not
     * marked Secure). This method checks all three valid signals.
     */
    public static function isHttps(): bool
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
        if (empty($_SESSION['user_id'])) return false;

        // Absolute session lifetime limit: since DbSessionHandler advances
        // expires_at on every request (sliding), without this check an active
        // user would never be forced to log in again after "session_ttl_hours"
        // from login.
        $loginTime = $_SESSION['login_time'] ?? null;
        if ($loginTime !== null) {
            $ttl = SettingsModel::getInt('session_ttl_hours', 1, 720, self::TTL_HOURS_DEFAULT) * 3600;
            if (time() - (int) $loginTime > $ttl) {
                self::destroy();
                return false;
            }
        }

        self::refreshFromDb();

        return true;
    }

    /**
     * Syncs the session's display fields (name/last name/email/role) with the
     * latest database values. Without this, when an admin or the user
     * themselves edited the profile, the changes wouldn't show on pages until
     * logout/login (since these fields were only cached in the session at
     * login time). Once per request is enough.
     */
    private static function refreshFromDb(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;

        $row = (new UserModel())->findById(self::id());
        if ($row === null) return; // user was deleted — session stays unchanged until TTL expiry

        $_SESSION['username']     = $row['username'];
        $_SESSION['display_name'] = $row['display_name'];
        $_SESSION['first_name']   = $row['first_name'] ?? '';
        $_SESSION['last_name']    = $row['last_name'] ?? '';
        $_SESSION['phone']        = $row['phone'] ?? '';
        $_SESSION['email']        = $row['email'] ?? '';
        $_SESSION['role']         = ($row['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
        $_SESSION['hidden_menus'] = (new MenuAccessModel())->getHidden(self::id());
    }

    /**
     * Ensures the session's CSRF token exists (creates it if missing) and returns it.
     * Single source of truth — instead of repeating this block across entry points.
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

    /** Access level stored in the session (display only — not a security reference) */
    public static function role(): string
    {
        return $_SESSION['role'] ?? 'user';
    }

    public static function isAdmin(): bool
    {
        return self::role() === 'admin';
    }

    /** Per-user menu visibility (see MenuAccessModel — opt-out, default visible) */
    public static function canViewProfile(): bool
    {
        return !in_array('profile', $_SESSION['hidden_menus'] ?? [], true);
    }

    public static function canViewNotifications(): bool
    {
        return !in_array('notifications', $_SESSION['hidden_menus'] ?? [], true);
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