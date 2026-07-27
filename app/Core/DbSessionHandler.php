<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// DbSessionHandler — stores sessions in the database (instead of files)
// ───────────────────────────────────────────────────────────
// Why the database? On shared hosting (cPanel), file-based session storage
// is limited and unreliable: aggressive /tmp cleanup, inode limits, no
// guaranteed writable path, and no sharing across multiple servers. This
// handler keeps sessions in the `sessions` table.
//
// Key performance point: reads are lock-free (no SELECT ... FOR UPDATE).
// So concurrent requests from the same user (e.g. bootstrap + notifications
// + unread_count loading together) don't block each other — unlike PHP's
// default file handler, which locks the session file for the whole request.
// The tradeoff: in rare race conditions, "last writer wins"
// (the same behavior as Laravel's database session driver).
//
// SessionUpdateTimestampHandlerInterface is implemented to support
// lazy_write: if the session data hasn't changed, only last_seen is updated.
//
// Absolute TTL ceiling: expires_at is set only on the first INSERT (at
// login) and is never extended on later writes — so the session expires
// exactly session_ttl_hours after login, regardless of user activity.
// ═══════════════════════════════════════════════════════════

class DbSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    private int $ttl;
    private bool $ensured = false;

    public function __construct(int $ttl = 86400)
    {
        $this->ttl = $ttl;
    }

    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }

    public function read(string $id): string
    {
        try {
            $row = DB::run(
                'SELECT payload FROM sessions WHERE id = :id AND expires_at > :now',
                [':id' => $id, ':now' => time()]
            )->fetch();
            return $row ? (string) $row['payload'] : '';
        } catch (\PDOException $e) {
            if ($this->ensureTable($e)) return '';
            throw $e;
        }
    }

    public function write(string $id, string $data): bool
    {
        // Don't store empty sessions (guests), to avoid bloating the table.
        if ($data === '') return true;

        $now = time();
        $params = [
            ':id'      => $id,
            ':uid'     => isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
            ':ip'      => mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            ':ua'      => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ':did'     => $_COOKIE['dash_device'] ?? null,
            ':payload' => $data,
            ':seen'    => $now,
            ':exp'     => $now + $this->ttl,
        ];
        try {
            // Absolute-ceiling note: expires_at is set only on the first INSERT
            // (the real start of the session) and is left untouched on later
            // UPDATEs — so the session's lifetime ceiling is fixed from login
            // and doesn't advance with user activity (unlike last_seen, which
            // is still updated to show "last activity").
            DB::run(
                'INSERT INTO sessions (id, user_id, ip, user_agent, device_id, payload, last_seen, expires_at)
                 VALUES (:id, :uid, :ip, :ua, :did, :payload, :seen, :exp)
                 ON DUPLICATE KEY UPDATE
                   user_id = VALUES(user_id), ip = VALUES(ip), user_agent = VALUES(user_agent),
                   device_id = VALUES(device_id), payload = VALUES(payload), last_seen = VALUES(last_seen)',
                $params
            );
            return true;
        } catch (\PDOException $e) {
            if ($this->ensureTable($e)) return $this->write($id, $data);
            throw $e;
        }
    }

    public function destroy(string $id): bool
    {
        try {
            DB::run('DELETE FROM sessions WHERE id = :id', [':id' => $id]);
        } catch (\PDOException $e) {
            if (!$this->ensureTable($e)) throw $e;
        }
        return true;
    }

    #[\ReturnTypeWillChange]
    public function gc(int $max_lifetime)
    {
        try {
            return DB::run('DELETE FROM sessions WHERE expires_at < :now', [':now' => time()])->rowCount();
        } catch (\PDOException $e) {
            if ($this->ensureTable($e)) return 0;
            return false;
        }
    }

    // ── lazy_write: ID validation (use_strict_mode) ──
    public function validateId(string $id): bool
    {
        try {
            return (bool) DB::run(
                'SELECT 1 FROM sessions WHERE id = :id AND expires_at > :now',
                [':id' => $id, ':now' => time()]
            )->fetch();
        } catch (\PDOException $e) {
            if ($this->ensureTable($e)) return false;
            throw $e;
        }
    }

    // ── lazy_write: updates last activity without rewriting the payload ──
    // expires_at is intentionally left untouched; the absolute TTL ceiling
    // from the first INSERT (login) stays fixed and isn't extended by activity.
    public function updateTimestamp(string $id, string $data): bool
    {
        $now = time();
        try {
            DB::run(
                'UPDATE sessions SET last_seen = :seen WHERE id = :id',
                [':seen' => $now, ':id' => $id]
            );
            return true;
        } catch (\PDOException $e) {
            if ($this->ensureTable($e)) return true;
            throw $e;
        }
    }

    /**
     * Auto-creates the table on first use if it doesn't exist.
     * Enables hassle-free deployment on shared hosting (no manual SQL needed).
     * Only triggers on a "table not found" error (SQLSTATE 42S02), and runs once.
     */
    private function ensureTable(\PDOException $e): bool
    {
        if ($this->ensured || (string) $e->getCode() !== '42S02') return false;
        DB::get()->exec(
            'CREATE TABLE IF NOT EXISTS sessions (
               id          VARCHAR(128)  NOT NULL,
               user_id     INT UNSIGNED  NULL DEFAULT NULL,
               ip          VARCHAR(45)   NULL DEFAULT NULL,
               user_agent  VARCHAR(255)  NULL DEFAULT NULL,
               device_id   VARCHAR(64)   NULL DEFAULT NULL,
               payload     MEDIUMBLOB    NOT NULL,
               last_seen   INT UNSIGNED  NOT NULL,
               expires_at  INT UNSIGNED  NOT NULL,
               PRIMARY KEY (id),
               KEY idx_expires (expires_at),
               KEY idx_user (user_id),
               KEY idx_user_device (user_id, device_id)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $this->ensured = true;
        return true;
    }
}
