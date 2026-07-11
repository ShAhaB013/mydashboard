<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// Fixtures — کمک‌کننده‌های PDO مستقیم برای ساخت/پاک‌سازی ردیف‌های تستی
// همه‌ی داده‌های تولیدی این فایل با پیشوند zztest_ مشخص می‌شوند تا
// sweep نهایی (run_all.php) بتواند به‌سادگی شناسایی و حذف‌شان کند.
// ═══════════════════════════════════════════════════════════

class Fixtures
{
    public const PREFIX = 'zztest_';

    /**
     * پاک‌سازی میکروکش پاسخ‌های مهمان — چون Fixtures مستقیم در DB می‌نویسد
     * (بدون عبور از مدل‌ها که خودشان invalidate می‌کنند)، بعد از هر نوشتن
     * صدا می‌شود تا تست‌های فید/ابزار مهمان نسخه‌ی stale نگیرند.
     * (سرور تست php -S روی همین ماشین است؛ sys_get_temp_dir مشترک است)
     */
    private static function flushGuestMicroCache(): void
    {
        MicroCache::forget('notif-guest');
        MicroCache::forget('tools-guest');
        MicroCache::forget('boot-guest');
    }

    public static function uniq(string $suffix = ''): string
    {
        return self::PREFIX . bin2hex(random_bytes(4)) . ($suffix !== '' ? '_' . $suffix : '');
    }

    public static function createTool(array $overrides = []): int
    {
        $data = array_merge([
            'title'       => self::uniq('tool'),
            'description' => 'fixture',
            'path'        => '/' . self::uniq('path'),
            'badge'       => 'zztest',
            'icon_key'    => 'star',
            'deco'        => 'generic',
            'accent_color'=> '#123456',
            'is_public'   => 0,
            'sort_order'  => 9999,
        ], $overrides);

        DB::run(
            'INSERT INTO tools (title, description, path, badge, icon_key, deco, accent_color, is_public, sort_order)
             VALUES (:title,:description,:path,:badge,:icon_key,:deco,:accent_color,:is_public,:sort_order)',
            $data
        );
        self::flushGuestMicroCache();
        return (int) DB::get()->lastInsertId();
    }

    public static function createUser(array $overrides = []): int
    {
        $username = $overrides['username'] ?? self::uniq('u');
        $data = array_merge([
            'username'      => $username,
            'phone'         => null,
            'email'         => null,
            'password_hash' => password_hash('ZzTest!Fixture2026', PASSWORD_BCRYPT),
            'first_name'    => 'زد',
            'last_name'     => 'تست',
            'display_name'  => $username,
            'role'          => 'user',
            'is_active'     => 1,
        ], $overrides);

        DB::run(
            'INSERT INTO users (username, phone, email, password_hash, first_name, last_name, display_name, role, is_active)
             VALUES (:username,:phone,:email,:password_hash,:first_name,:last_name,:display_name,:role,:is_active)',
            $data
        );
        return (int) DB::get()->lastInsertId();
    }

    public static function createNotification(array $overrides = []): int
    {
        $data = array_merge([
            'title'             => self::uniq('notif'),
            'body'              => 'fixture body',
            'image_path'        => null,
            'thumbnail_path'    => null,
            'is_public'         => 1,
            'target_all_users'  => 1,
            'expires_at'        => 0,
        ], $overrides);

        // created_at/updated_at اختیاری‌اند (پیش‌فرض DB = CURRENT_TIMESTAMP)؛ فقط وقتی صریحا
        // داده شوند وارد کوئری می‌شوند تا فراخوان‌های موجود بدون تغییر کار کنند.
        $cols = ['title', 'body', 'image_path', 'thumbnail_path', 'is_public', 'target_all_users', 'expires_at'];
        foreach (['created_at', 'updated_at'] as $c) {
            if (array_key_exists($c, $overrides)) $cols[] = $c;
        }
        $colList = implode(',', $cols);
        $phList  = implode(',', array_map(fn($c) => ":{$c}", $cols));
        $params  = array_intersect_key($data, array_flip($cols));

        DB::run("INSERT INTO notifications ({$colList}) VALUES ({$phList})", $params);
        self::flushGuestMicroCache();
        return (int) DB::get()->lastInsertId();
    }

    public static function deleteToolsByPrefix(): int
    {
        $ids = DB::run("SELECT id FROM tools WHERE title LIKE :p", [':p' => self::PREFIX . '%'])->fetchAll();
        $n = 0;
        foreach ($ids as $row) {
            DB::run('DELETE FROM tool_access WHERE tool_id = :id', [':id' => $row['id']]);
            DB::run('DELETE FROM tools WHERE id = :id', [':id' => $row['id']]);
            $n++;
        }
        self::flushGuestMicroCache();
        return $n;
    }

    public static function deleteUsersByPrefix(bool $includeFixedAccounts = false): int
    {
        $like = self::PREFIX . '%';
        $sql = $includeFixedAccounts
            ? "SELECT id FROM users WHERE username LIKE :p"
            : "SELECT id FROM users WHERE username LIKE :p AND username NOT IN ('zztest_admin','zztest_user','zztest_locked')";
        $rows = DB::run($sql, [':p' => $like])->fetchAll();
        $n = 0;
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            DB::run('DELETE FROM category_access WHERE user_id = :id', [':id' => $id]);
            DB::run('DELETE FROM tool_access WHERE user_id = :id', [':id' => $id]);
            DB::run('DELETE FROM notification_reads WHERE user_id = :id', [':id' => $id]);
            DB::run('DELETE FROM sessions WHERE user_id = :id', [':id' => $id]);
            DB::run('DELETE FROM users WHERE id = :id', [':id' => $id]);
            $n++;
        }
        return $n;
    }

    public static function deleteNotificationsByPrefix(): int
    {
        $rows = DB::run("SELECT id FROM notifications WHERE title LIKE :p", [':p' => self::PREFIX . '%'])->fetchAll();
        $n = 0;
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            DB::run('DELETE FROM notification_badges WHERE notification_id = :id', [':id' => $id]);
            DB::run('DELETE FROM notification_reads WHERE notification_id = :id', [':id' => $id]);
            DB::run('DELETE FROM notifications WHERE id = :id', [':id' => $id]);
            $n++;
        }
        self::flushGuestMicroCache();
        return $n;
    }

    public static function deleteRateLimitByIp(string $ip, string $scope = ''): void
    {
        if ($scope !== '') {
            DB::run('DELETE FROM login_rate_limit WHERE ip = :ip AND scope = :scope', [':ip' => $ip, ':scope' => $scope]);
        } else {
            DB::run('DELETE FROM login_rate_limit WHERE ip = :ip', [':ip' => $ip]);
        }
    }

    public static function deleteSyntheticRateLimits(): int
    {
        // رنج TEST-NET-3 (RFC 5737) — هرگز یک IP واقعی کلاینت نیست
        $n = DB::run("DELETE FROM login_rate_limit WHERE ip LIKE '203.0.113.%'")->rowCount();
        return $n;
    }

    public static function sweepAll(): array
    {
        return [
            'tools'         => self::deleteToolsByPrefix(),
            'users'         => self::deleteUsersByPrefix(false),
            'notifications' => self::deleteNotificationsByPrefix(),
            'rate_limits'   => self::deleteSyntheticRateLimits(),
        ];
    }

    public static function findToolByTitle(string $title): ?array
    {
        $row = DB::run('SELECT * FROM tools WHERE title = :t', [':t' => $title])->fetch();
        return $row ?: null;
    }

    public static function findUserByUsername(string $username): ?array
    {
        $row = DB::run('SELECT * FROM users WHERE username = :u', [':u' => $username])->fetch();
        return $row ?: null;
    }

    public static function ensureFixedAccount(string $username, string $password, string $role, int $isActive = 1): int
    {
        $existing = self::findUserByUsername($username);
        $hash = password_hash($password, PASSWORD_BCRYPT);
        if ($existing) {
            DB::run(
                'UPDATE users SET password_hash=:h, role=:r, is_active=:a, reset_code_hash=NULL, reset_expires=NULL, reset_attempts=0 WHERE id=:id',
                [':h' => $hash, ':r' => $role, ':a' => $isActive, ':id' => $existing['id']]
            );
            return (int) $existing['id'];
        }
        return self::createUser([
            'username' => $username, 'password_hash' => $hash, 'role' => $role, 'is_active' => $isActive,
            'display_name' => $username,
        ]);
    }
}
