<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// UserModel — عملیات CRUD روی کاربران (شامل سطح دسترسی role)
// role: 'user' (عادی) | 'admin' (مدیر پنل)
// ═══════════════════════════════════════════════════════════

class UserModel
{
    /** فهرست مجاز سطوح دسترسی */
    public const ROLES = ['user', 'admin'];

    /** نرمال‌سازی role ورودی به یکی از مقادیر مجاز */
    public static function normalizeRole(string $role): string
    {
        $role = strtolower(trim($role));
        return in_array($role, self::ROLES, true) ? $role : 'user';
    }

    /**
     * تقسیم «نام و نام خانوادگی» (ورودی تکی) به نام و فامیل.
     * اولین واژه = نام، بقیه = نام خانوادگی. فاصله‌های اضافی نرمال می‌شوند.
     * @return array{0:string,1:string} [first, last]
     */
    public static function splitName(string $full): array
    {
        $full = trim((string) preg_replace('/\s+/u', ' ', $full));
        $i = mb_strpos($full, ' ');
        if ($i === false) {
            return [$full, ''];
        }
        return [mb_substr($full, 0, $i), trim(mb_substr($full, $i + 1))];
    }

    /** دریافت همه کاربران */
    public function all(): array
    {
        return DB::run(
            'SELECT id, username, first_name, last_name, display_name, email, role, is_active, created_at
             FROM users
             ORDER BY id ASC'
        )->fetchAll();
    }

    /**
     * صفحه‌بندی سمت سرور + جستجوی اختیاری (نام نمایشی/نام/فامیل/ایمیل/نام‌کاربری).
     * فقط ردیف‌های صفحه جاری از DB می‌آیند.
     */
    public function allPaginated(int $page, int $perPage, string $search = ''): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset  = ($page - 1) * $perPage;
        $like    = '%' . $search . '%';
        $limitSql = sprintf('LIMIT %d OFFSET %d', $perPage, $offset);

        return DB::run(
            'SELECT id, username, first_name, last_name, display_name, email, role, is_active, created_at
             FROM users
             WHERE (:search = \'\'
                    OR display_name LIKE :like OR first_name LIKE :like2 OR last_name LIKE :like3
                    OR email LIKE :like4 OR username LIKE :like5)
             ORDER BY id ASC
             ' . $limitSql,
            [':search' => $search, ':like' => $like, ':like2' => $like,
             ':like3' => $like, ':like4' => $like, ':like5' => $like]
        )->fetchAll();
    }

    /** تعداد کل کاربران برای صفحه‌بندی (با جستجوی اختیاری) */
    public function countAll(string $search = ''): int
    {
        $like = '%' . $search . '%';
        return (int) DB::run(
            'SELECT COUNT(*) FROM users
             WHERE (:search = \'\'
                    OR display_name LIKE :like OR first_name LIKE :like2 OR last_name LIKE :like3
                    OR email LIKE :like4 OR username LIKE :like5)',
            [':search' => $search, ':like' => $like, ':like2' => $like,
             ':like3' => $like, ':like4' => $like, ':like5' => $like]
        )->fetchColumn();
    }

    /** یافتن کاربر با ID */
    public function findById(int $id): ?array
    {
        $row = DB::run(
            'SELECT id, username, first_name, last_name, display_name, email, role, is_active, created_at
             FROM users WHERE id = :id',
            [':id' => $id]
        )->fetch();
        return $row ?: null;
    }

    /** تعداد ادمین‌های فعال — برای جلوگیری از قفل‌شدن پنل */
    public function countActiveAdmins(): int
    {
        return (int) DB::run(
            "SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1"
        )->fetchColumn();
    }

    /**
     * آیا این کاربر «آخرین ادمین فعال» است؟
     * (حذف/تنزل/غیرفعال‌کردن آن باعث قفل‌شدن پنل می‌شود)
     */
    public function isLastActiveAdmin(int $id): bool
    {
        $row = $this->findById($id);
        if (!$row || ($row['role'] ?? 'user') !== 'admin' || (int) $row['is_active'] !== 1) {
            return false;
        }
        return $this->countActiveAdmins() <= 1;
    }

    /** بررسی وجود username */
    public function usernameExists(string $username, int $excludeId = 0): bool
    {
        $row = DB::run(
            'SELECT id FROM users WHERE username = :u AND id != :ex',
            [':u' => $username, ':ex' => $excludeId]
        )->fetch();
        return (bool) $row;
    }

    /** بررسی وجود email (برای یکتایی هنگام ایجاد/ویرایش در پنل) */
    public function emailExists(string $email, int $excludeId = 0): bool
    {
        $row = DB::run(
            'SELECT id FROM users WHERE email = :e AND id != :ex',
            [':e' => $email, ':ex' => $excludeId]
        )->fetch();
        return (bool) $row;
    }

    /**
     * ساخت یک username یکتا و خودکار از روی ایمیل.
     * بخش محلی ایمیل را پاک‌سازی می‌کند (فقط a-z0-9_، شروع با حرف) و یک
     * پسوند تصادفی می‌افزاید تا با هیچ کاربر دیگری برخورد نکند.
     */
    public function generateUniqueUsername(string $email): string
    {
        $local = strtolower(substr($email, 0, (int) strrpos($email, '@')));
        $base  = preg_replace('/[^a-z0-9_]/', '', $local) ?? '';
        if ($base === '' || !preg_match('/^[a-z]/', $base)) {
            $base = 'u' . $base;
        }
        $base = substr($base, 0, 40); // فضای کافی برای پسوند (سقف ستون ۶۰)

        do {
            $suffix   = bin2hex(random_bytes(3)); // ۶ کاراکتر hex
            $username = $base . '_' . $suffix;
        } while ($this->usernameExists($username));

        return $username;
    }

    /** افزودن کاربر جدید (username خودکار از روی ایمیل) */
    public function create(string $firstName, string $lastName, string $email, string $password, string $role = 'user'): int
    {
        $displayName = trim($firstName . ' ' . $lastName);
        DB::run(
            'INSERT INTO users (username, password_hash, first_name, last_name, display_name, email, email_verified, role, is_active)
             VALUES (:u, :h, :f, :l, :d, :e, 1, :r, 1)',
            [
                ':u' => $this->generateUniqueUsername($email),
                ':h' => password_hash($password, PASSWORD_BCRYPT),
                ':f' => $firstName,
                ':l' => $lastName,
                ':d' => $displayName,
                ':e' => $email,
                ':r' => self::normalizeRole($role),
            ]
        );
        return (int) DB::get()->lastInsertId();
    }

    /** ویرایش اطلاعات کاربر (نام/نام‌خانوادگی/ایمیل/role، بدون تغییر رمز یا username) */
    public function update(int $id, string $firstName, string $lastName, string $email, string $role = 'user'): bool
    {
        DB::run(
            'UPDATE users SET first_name = :f, last_name = :l, display_name = :d, email = :e, role = :r WHERE id = :id',
            [
                ':f'  => $firstName,
                ':l'  => $lastName,
                ':d'  => trim($firstName . ' ' . $lastName),
                ':e'  => $email,
                ':r'  => self::normalizeRole($role),
                ':id' => $id,
            ]
        );
        return true;
    }

    /** تغییر رمز عبور */
    public function changePassword(int $id, string $newPassword): bool
    {
        DB::run(
            'UPDATE users SET password_hash = :h WHERE id = :id',
            [':h' => password_hash($newPassword, PASSWORD_BCRYPT), ':id' => $id]
        );
        return true;
    }

    /** به‌روزرسانی فقط ایمیل کاربر (پس از تایید کد تغییر ایمیل) */
    public function updateEmail(int $id, string $email): bool
    {
        DB::run(
            'UPDATE users SET email = :e, email_verified = 1 WHERE id = :id',
            [':e' => $email, ':id' => $id]
        );
        return true;
    }

    /** فعال/غیرفعال کردن کاربر */
    public function toggleActive(int $id): bool
    {
        DB::run(
            'UPDATE users SET is_active = 1 - is_active WHERE id = :id',
            [':id' => $id]
        );
        return true;
    }

    /** حذف کاربر (cascade روی tool_access و category_access) */
    public function delete(int $id): bool
    {
        DB::run('DELETE FROM users WHERE id = :id', [':id' => $id]);
        return true;
    }

    // ── ثبت‌نام با تایید ایمیل ───────────────────────────────────

    /** آیا username متعلق به یک کاربر فعال است؟ */
    public function usernameExistsActive(string $username): bool
    {
        return (bool) DB::run(
            'SELECT id FROM users WHERE username = :u AND is_active = 1',
            [':u' => $username]
        )->fetch();
    }

    /** آیا email متعلق به یک کاربر فعال است؟ */
    public function emailExistsActive(string $email): bool
    {
        return (bool) DB::run(
            'SELECT id FROM users WHERE email = :e AND is_active = 1',
            [':e' => $email]
        )->fetch();
    }

    /** پاک‌سازی رکوردهای نیمه‌کاره تاییدنشده برای این ایمیل (برای تلاش مجدد بدون تداخل unique) */
    public function deletePendingByEmail(string $email): void
    {
        DB::run(
            'DELETE FROM users WHERE is_active = 0 AND email_verified = 0 AND email = :e',
            [':e' => $email]
        );
    }

    /**
     * ساخت کاربر غیرفعال (در انتظار تایید ایمیل) با کد تایید.
     * username خودکار از روی ایمیل ساخته می‌شود؛ نام نمایشی = نام + نام خانوادگی.
     */
    public function createPending(string $firstName, string $lastName, string $email, string $password, string $codeHash, int $expires): int
    {
        DB::run(
            'INSERT INTO users
                (username, password_hash, first_name, last_name, display_name, email, email_verified, verify_code, verify_expires, verify_attempts, role, is_active)
             VALUES (:u, :h, :f, :l, :d, :e, 0, :c, :x, 0, "user", 0)',
            [
                ':u' => $this->generateUniqueUsername($email),
                ':h' => password_hash($password, PASSWORD_BCRYPT),
                ':f' => $firstName,
                ':l' => $lastName,
                ':d' => trim($firstName . ' ' . $lastName),
                ':e' => $email,
                ':c' => $codeHash,
                ':x' => $expires,
            ]
        );
        return (int) DB::get()->lastInsertId();
    }

    /** یافتن کاربر در انتظار تایید با email */
    public function findPendingByEmail(string $email): ?array
    {
        $row = DB::run(
            'SELECT * FROM users WHERE email = :e AND is_active = 0 AND email_verified = 0',
            [':e' => $email]
        )->fetch();
        return $row ?: null;
    }

    /** جایگزینی کد تایید (برای ارسال مجدد) */
    public function setVerifyCode(int $id, string $codeHash, int $expires): void
    {
        DB::run(
            'UPDATE users SET verify_code = :c, verify_expires = :x, verify_attempts = 0 WHERE id = :id',
            [':c' => $codeHash, ':x' => $expires, ':id' => $id]
        );
    }

    /** ثبت یک تلاش ناموفق تایید */
    public function incrementVerifyAttempts(int $id): void
    {
        DB::run('UPDATE users SET verify_attempts = verify_attempts + 1 WHERE id = :id', [':id' => $id]);
    }

    // ── بازیابی رمز عبور (فراموشی رمز) ─────────────────────────

    /** یافتن کاربر فعال با ایمیل (برای فلوی فراموشی رمز) */
    public function findActiveByEmail(string $email): ?array
    {
        $row = DB::run(
            'SELECT * FROM users WHERE email = :e AND is_active = 1',
            [':e' => $email]
        )->fetch();
        return $row ?: null;
    }

    /** پاک‌سازی کد تایید/بازیابی پس از استفاده موفق */
    public function clearVerifyCode(int $id): void
    {
        DB::run(
            'UPDATE users SET verify_code = NULL, verify_expires = NULL, verify_attempts = 0 WHERE id = :id',
            [':id' => $id]
        );
    }

    /** فعال‌سازی حساب پس از تایید موفق ایمیل */
    public function activateVerified(int $id): void
    {
        DB::run(
            'UPDATE users SET is_active = 1, email_verified = 1, verify_code = NULL, verify_expires = NULL, verify_attempts = 0 WHERE id = :id',
            [':id' => $id]
        );
    }
}