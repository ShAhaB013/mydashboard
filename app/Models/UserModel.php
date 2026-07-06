<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// UserModel — عملیات CRUD روی کاربران (شامل سطح دسترسی role)
// role: 'user' (عادی) | 'admin' (مدیر پنل)
// کاربران فقط توسط ادمین ساخته می‌شوند (بدون ثبت‌نام عمومی/ایمیل).
// ═══════════════════════════════════════════════════════════

class UserModel
{
    /** فهرست مجاز سطوح دسترسی */
    public const ROLES = ['user', 'admin'];

    /** هزینه‌ی bcrypt — از پیش‌فرضِ ۱۰ به ۱۲ افزایش یافت (کندسازیِ brute-force آفلاین) */
    public const BCRYPT_COST = 12;

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
            'SELECT id, username, first_name, last_name, display_name, phone, email, role, is_active, created_at
             FROM users
             ORDER BY id ASC'
        )->fetchAll();
    }

    /**
     * صفحه‌بندی سمت سرور + جستجوی اختیاری (نام نمایشی/نام/فامیل/موبایل/نام‌کاربری/ایمیل).
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
            'SELECT id, username, first_name, last_name, display_name, phone, email, role, is_active, created_at
             FROM users
             WHERE (:search = \'\'
                    OR display_name LIKE :like OR first_name LIKE :like2 OR last_name LIKE :like3
                    OR phone LIKE :like4 OR username LIKE :like5 OR email LIKE :like6)
             ORDER BY id ASC
             ' . $limitSql,
            [':search' => $search, ':like' => $like, ':like2' => $like,
             ':like3' => $like, ':like4' => $like, ':like5' => $like, ':like6' => $like]
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
                    OR phone LIKE :like4 OR username LIKE :like5 OR email LIKE :like6)',
            [':search' => $search, ':like' => $like, ':like2' => $like,
             ':like3' => $like, ':like4' => $like, ':like5' => $like, ':like6' => $like]
        )->fetchColumn();
    }

    /** یافتن کاربر با ID */
    public function findById(int $id): ?array
    {
        $row = DB::run(
            'SELECT id, username, first_name, last_name, display_name, phone, email, role, is_active, created_at
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

    /** بررسی وجود شماره موبایل (برای یکتایی هنگام ایجاد/ویرایش در پنل) */
    public function phoneExists(string $phone, int $excludeId = 0): bool
    {
        $row = DB::run(
            'SELECT id FROM users WHERE phone = :p AND id != :ex',
            [':p' => $phone, ':ex' => $excludeId]
        )->fetch();
        return (bool) $row;
    }

    /** بررسی وجود ایمیل (برای یکتایی هنگام ایجاد/ویرایش در پنل) */
    public function emailExists(string $email, int $excludeId = 0): bool
    {
        $row = DB::run(
            'SELECT id FROM users WHERE email = :e AND id != :ex',
            [':e' => $email, ':ex' => $excludeId]
        )->fetch();
        return (bool) $row;
    }

    /** افزودن کاربر جدید (نام‌کاربری و شماره موبایل توسط ادمین تعیین می‌شوند) */
    public function create(string $firstName, string $lastName, string $username, string $phone, string $email, string $password, string $role = 'user'): int
    {
        $displayName = trim($firstName . ' ' . $lastName);
        DB::run(
            'INSERT INTO users (username, password_hash, first_name, last_name, display_name, phone, email, role, is_active)
             VALUES (:u, :h, :f, :l, :d, :p, :e, :r, 1)',
            [
                ':u' => $username,
                ':h' => password_hash($password, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]),
                ':f' => $firstName,
                ':l' => $lastName,
                ':d' => $displayName,
                ':p' => ($phone === '' ? null : $phone),   // خالی → NULL (سازگار با UNIQUE index)
                ':e' => $email,
                ':r' => self::normalizeRole($role),
            ]
        );
        return (int) DB::get()->lastInsertId();
    }

    /** ویرایش اطلاعات کاربر (نام/نام‌خانوادگی/موبایل/ایمیل/role، بدون تغییر رمز یا username) */
    public function update(int $id, string $firstName, string $lastName, string $phone, string $email, string $role = 'user'): bool
    {
        DB::run(
            'UPDATE users SET first_name = :f, last_name = :l, display_name = :d, phone = :p, email = :e, role = :r WHERE id = :id',
            [
                ':f'  => $firstName,
                ':l'  => $lastName,
                ':d'  => trim($firstName . ' ' . $lastName),
                ':p'  => ($phone === '' ? null : $phone),   // خالی → NULL (سازگار با UNIQUE index)
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
            [':h' => password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]), ':id' => $id]
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

    // ── بازیابی رمز عبور با OTP ایمیلی (فراموشی رمز) ────────────

    /** یافتن کاربر فعال با ایمیل (برای فلوی فراموشی رمز) */
    public function findActiveByEmail(string $email): ?array
    {
        $row = DB::run(
            'SELECT * FROM users WHERE email = :e AND is_active = 1',
            [':e' => $email]
        )->fetch();
        return $row ?: null;
    }

    /** ثبت کد OTP جدید برای ریست رمز (جایگزین کد قبلی، شمارنده تلاش صفر می‌شود) */
    public function setResetCode(int $id, string $codeHash, int $expires): void
    {
        DB::run(
            'UPDATE users SET reset_code_hash = :c, reset_expires = :x, reset_attempts = 0 WHERE id = :id',
            [':c' => $codeHash, ':x' => $expires, ':id' => $id]
        );
    }

    /** ثبت یک تلاش ناموفق تایید کد ریست */
    public function incrementResetAttempts(int $id): void
    {
        DB::run('UPDATE users SET reset_attempts = reset_attempts + 1 WHERE id = :id', [':id' => $id]);
    }

    /** پاک‌سازی کد ریست پس از استفاده موفق */
    public function clearResetCode(int $id): void
    {
        DB::run(
            'UPDATE users SET reset_code_hash = NULL, reset_expires = NULL, reset_attempts = 0 WHERE id = :id',
            [':id' => $id]
        );
    }
}
