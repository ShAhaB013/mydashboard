<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// Fixtures — direct PDO helpers for creating/cleaning up test rows
// All data produced by this file is tagged with the zztest_ prefix so the
// final sweep (run_all.php) can easily identify and remove it.
// ═══════════════════════════════════════════════════════════

class Fixtures
{
    public const PREFIX = 'zztest_';

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
            'sort_order'  => 9999,
        ], $overrides);

        $categoryId = (new CategoryModel())->findOrCreateByName((string) $data['badge']);
        unset($data['badge']);
        $data['category_id'] = $categoryId;

        DB::run(
            'INSERT INTO tools (title, description, path, category_id, icon_key, deco, accent_color, sort_order)
             VALUES (:title,:description,:path,:category_id,:icon_key,:deco,:accent_color,:sort_order)',
            $data
        );
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
        $id = (int) DB::get()->lastInsertId();

        // A raw fixture insert bypasses UserController::create()'s fan-out call — without this,
        // a fixture user would never see target_all_users=1 notifications under the
        // notification_recipients architecture (see NotificationModel::refreshRecipientsForUser).
        (new NotificationModel())->refreshRecipientsForUser($id);

        return $id;
    }

    public static function createNotification(array $overrides = []): int
    {
        $data = array_merge([
            'title'             => self::uniq('notif'),
            'body'              => 'fixture body',
            'image_path'        => null,
            'thumbnail_path'    => null,
            'target_all_users'  => 1,
            'expires_at'        => 0,
        ], $overrides);

        // created_at/updated_at are optional (DB default = CURRENT_TIMESTAMP); they're only added
        // to the query when explicitly passed, so existing callers keep working unchanged.
        $cols = ['title', 'body', 'image_path', 'thumbnail_path', 'target_all_users', 'expires_at'];
        foreach (['created_at', 'updated_at'] as $c) {
            if (array_key_exists($c, $overrides)) $cols[] = $c;
        }
        $colList = implode(',', $cols);
        $phList  = implode(',', array_map(fn($c) => ":{$c}", $cols));
        $params  = array_intersect_key($data, array_flip($cols));

        DB::run("INSERT INTO notifications ({$colList}) VALUES ({$phList})", $params);
        $id = (int) DB::get()->lastInsertId();

        // Same reasoning as createUser() above: a raw insert bypasses NotificationModel::create()'s
        // fan-out, so notification_recipients must be seeded here directly for fixture notifications
        // to actually show up in any bell/history read.
        (new NotificationModel())->refreshRecipientsForNotification($id);

        return $id;
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
        // TEST-NET-3 range (RFC 5737) — never a real client IP
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
