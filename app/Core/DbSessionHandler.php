<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// DbSessionHandler — ذخیره‌سازی نشست در دیتابیس (به‌جای فایل)
// ───────────────────────────────────────────────────────────
// چرا دیتابیس؟ روی هاست اشتراکی (cPanel) ذخیره‌سازی فایلی نشست
// محدود و ناپایدار است: پاکسازی تهاجمی /tmp، محدودیت inode،
// نبود مسیر نوشتنی مطمئن، و عدم اشتراک بین چند سرور. این هندلر
// نشست‌ها را در جدول `sessions` نگه می‌دارد.
//
// نکته کلیدی کارایی: خواندن «بدون قفل» است (بدون SELECT ... FOR UPDATE).
// در نتیجه چند درخواست هم‌زمان یک کاربر (مثلا bootstrap + notifications +
// unread_count که با هم لود می‌شوند) روی هم قفل نمی‌شوند — برخلاف هندلر
// فایلی پیش‌فرض PHP که فایل نشست را تا پایان درخواست قفل می‌کند.
// بهای این انتخاب: در شرایط رقابتی نادر «آخرین نویسنده برنده است»
// (همان رفتار درایور database در Laravel).
//
// SessionUpdateTimestampHandlerInterface برای پشتیبانی lazy_write:
// اگر داده نشست تغییر نکند، فقط مهر زمانی (انقضا) تمدید می‌شود.
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
        // نشست خالی (مهمان‌ها) را ذخیره نکن تا جدول پر نشود.
        if ($data === '') return true;

        $now = time();
        $params = [
            ':id'      => $id,
            ':uid'     => isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
            ':ip'      => mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            ':ua'      => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ':payload' => $data,
            ':seen'    => $now,
            ':exp'     => $now + $this->ttl,
        ];
        try {
            DB::run(
                'INSERT INTO sessions (id, user_id, ip, user_agent, payload, last_seen, expires_at)
                 VALUES (:id, :uid, :ip, :ua, :payload, :seen, :exp)
                 ON DUPLICATE KEY UPDATE
                   user_id = VALUES(user_id), ip = VALUES(ip), user_agent = VALUES(user_agent),
                   payload = VALUES(payload), last_seen = VALUES(last_seen), expires_at = VALUES(expires_at)',
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

    // ── lazy_write: اعتبارسنجی شناسه (use_strict_mode) ──
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

    // ── lazy_write: تمدید انقضا بدون بازنویسی payload ──
    public function updateTimestamp(string $id, string $data): bool
    {
        $now = time();
        try {
            DB::run(
                'UPDATE sessions SET last_seen = :seen, expires_at = :exp WHERE id = :id',
                [':seen' => $now, ':exp' => $now + $this->ttl, ':id' => $id]
            );
            return true;
        } catch (\PDOException $e) {
            if ($this->ensureTable($e)) return true;
            throw $e;
        }
    }

    /**
     * ساخت خودکار جدول در نخستین استفاده اگر وجود نداشته باشد.
     * مناسب استقرار بدون-دردسر روی هاست اشتراکی (نیازی به اجرای دستی SQL نیست).
     * فقط برای خطای «جدول یافت نشد» (SQLSTATE 42S02) عمل می‌کند و یک‌بار اجرا می‌شود.
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
               payload     MEDIUMBLOB    NOT NULL,
               last_seen   INT UNSIGNED  NOT NULL,
               expires_at  INT UNSIGNED  NOT NULL,
               PRIMARY KEY (id),
               KEY idx_expires (expires_at),
               KEY idx_user (user_id)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $this->ensured = true;
        return true;
    }
}
