<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// SessionModel — queries active sessions (`sessions` table) for admin management.
// (Session storage/reading itself happens in DbSessionHandler; this model is
//  only for displaying and terminating sessions in the panel.)
// ═══════════════════════════════════════════════════════════

class SessionModel
{
    /** List of active sessions (with the username), paginated. $userId=null -> all users. */
    public static function active(?int $userId = null, int $limit = 300, int $offset = 0): array
    {
        $limit  = max(1, min(300, $limit));
        $offset = max(0, $offset);
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
        $sql .= ' ORDER BY s.last_seen DESC LIMIT ' . $limit . ' OFFSET ' . $offset;
        try {
            return DB::run($sql, $params)->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Total number of active sessions — same scope as active(), for pagination's has_more */
    public static function activeCount(?int $userId = null): int
    {
        $sql    = 'SELECT COUNT(*) FROM sessions WHERE expires_at > :now';
        $params = [':now' => time()];
        if ($userId !== null) {
            $sql .= ' AND user_id = :uid';
            $params[':uid'] = $userId;
        }
        try {
            return (int) DB::run($sql, $params)->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** Counts active sessions per user -> [user_id => count] */
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

    /** Terminates a specific session (by ID) */
    public static function terminate(string $id): bool
    {
        try {
            DB::run('DELETE FROM sessions WHERE id = :id', [':id' => $id]);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Terminates all of a user's sessions (optionally except one) -> number deleted */
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

    /** Terminates all sessions except the current one -> number deleted */
    public static function terminateOthers(string $exceptId): int
    {
        try {
            return DB::run('DELETE FROM sessions WHERE id <> :ex', [':ex' => $exceptId])->rowCount();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** Terminates a session only if it belongs to this user (server-enforced ownership) */
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

    /** Readable User-Agent summary for display (browser · OS) */
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
