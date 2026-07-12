<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// UserModel — CRUD operations on users (including the role access level)
// role: 'user' (regular) | 'admin' (panel admin)
// Users are only created by an admin (no public signup/email registration).
// ═══════════════════════════════════════════════════════════

class UserModel
{
    public const ROLES = ['user', 'admin'];

    /** bcrypt cost — raised from the default of 10 to 12 to slow down offline brute-force */
    public const BCRYPT_COST = 12;

    public static function normalizeRole(string $role): string
    {
        $role = strtolower(trim($role));
        return in_array($role, self::ROLES, true) ? $role : 'user';
    }

    /**
     * Split a single "full name" input into first/last name.
     * First word = first name, the rest = last name. Extra whitespace is normalized.
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

    public function all(): array
    {
        return DB::run(
            'SELECT id, username, first_name, last_name, display_name, phone, email, role, is_active, created_at
             FROM users
             ORDER BY id ASC'
        )->fetchAll();
    }

    /**
     * Build the advanced filter clause (role + status) for the admin list.
     * $filters: ['role'=>'admin|user', 'status'=>'active|inactive']
     */
    private function buildAdminFilters(array $filters, array &$params): string
    {
        $sql  = '';
        $role = trim((string) ($filters['role'] ?? ''));
        $st   = trim((string) ($filters['status'] ?? ''));

        if (in_array($role, self::ROLES, true)) {
            $sql .= ' AND role = :f_role';
            $params[':f_role'] = $role;
        }
        if ($st === 'active' || $st === 'inactive') {
            $sql .= ' AND is_active = :f_active';
            $params[':f_active'] = $st === 'active' ? 1 : 0;
        }
        return $sql;
    }

    /**
     * Server-side pagination + optional search (display name/first/last/phone/username/email)
     * + advanced filters (role/status). Only the rows for the current page are fetched from the DB.
     */
    public function allPaginated(int $page, int $perPage, string $search = '', array $filters = []): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset  = ($page - 1) * $perPage;
        $like    = '%' . $search . '%';
        $limitSql = sprintf('LIMIT %d OFFSET %d', $perPage, $offset);

        $params = [':search' => $search, ':like' => $like, ':like2' => $like,
             ':like3' => $like, ':like4' => $like, ':like5' => $like, ':like6' => $like];
        $filterSql = $this->buildAdminFilters($filters, $params);

        return DB::run(
            'SELECT id, username, first_name, last_name, display_name, phone, email, role, is_active, created_at
             FROM users
             WHERE (:search = \'\'
                    OR display_name LIKE :like OR first_name LIKE :like2 OR last_name LIKE :like3
                    OR phone LIKE :like4 OR username LIKE :like5 OR email LIKE :like6)
             ' . $filterSql . '
             ORDER BY id ASC
             ' . $limitSql,
            $params
        )->fetchAll();
    }

    public function countAll(string $search = '', array $filters = []): int
    {
        $like = '%' . $search . '%';
        $params = [':search' => $search, ':like' => $like, ':like2' => $like,
             ':like3' => $like, ':like4' => $like, ':like5' => $like, ':like6' => $like];
        $filterSql = $this->buildAdminFilters($filters, $params);

        return (int) DB::run(
            'SELECT COUNT(*) FROM users
             WHERE (:search = \'\'
                    OR display_name LIKE :like OR first_name LIKE :like2 OR last_name LIKE :like3
                    OR phone LIKE :like4 OR username LIKE :like5 OR email LIKE :like6)
             ' . $filterSql,
            $params
        )->fetchColumn();
    }

    public function findById(int $id): ?array
    {
        $row = DB::run(
            'SELECT id, username, first_name, last_name, display_name, phone, email, role, is_active, created_at
             FROM users WHERE id = :id',
            [':id' => $id]
        )->fetch();
        return $row ?: null;
    }

    /** Count of active admins — used to prevent locking the panel out */
    public function countActiveAdmins(): int
    {
        return (int) DB::run(
            "SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1"
        )->fetchColumn();
    }

    /**
     * Is this user the "last active admin"?
     * (deleting/demoting/deactivating them would lock the panel out)
     */
    public function isLastActiveAdmin(int $id): bool
    {
        $row = $this->findById($id);
        if (!$row || ($row['role'] ?? 'user') !== 'admin' || (int) $row['is_active'] !== 1) {
            return false;
        }
        return $this->countActiveAdmins() <= 1;
    }

    public function usernameExists(string $username, int $excludeId = 0): bool
    {
        $row = DB::run(
            'SELECT id FROM users WHERE username = :u AND id != :ex',
            [':u' => $username, ':ex' => $excludeId]
        )->fetch();
        return (bool) $row;
    }

    /** Uniqueness check for the phone number when creating/editing in the panel */
    public function phoneExists(string $phone, int $excludeId = 0): bool
    {
        $row = DB::run(
            'SELECT id FROM users WHERE phone = :p AND id != :ex',
            [':p' => $phone, ':ex' => $excludeId]
        )->fetch();
        return (bool) $row;
    }

    /** Uniqueness check for the email when creating/editing in the panel */
    public function emailExists(string $email, int $excludeId = 0): bool
    {
        $row = DB::run(
            'SELECT id FROM users WHERE email = :e AND id != :ex',
            [':e' => $email, ':ex' => $excludeId]
        )->fetch();
        return (bool) $row;
    }

    /** Add a new user (username and phone number are set by the admin) */
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
                ':p' => ($phone === '' ? null : $phone),   // empty → NULL (compatible with the UNIQUE index)
                ':e' => $email,
                ':r' => self::normalizeRole($role),
            ]
        );
        return (int) DB::get()->lastInsertId();
    }

    /** Edit user info (first/last name/phone/email/role, without changing password or username) */
    public function update(int $id, string $firstName, string $lastName, string $phone, string $email, string $role = 'user'): bool
    {
        DB::run(
            'UPDATE users SET first_name = :f, last_name = :l, display_name = :d, phone = :p, email = :e, role = :r WHERE id = :id',
            [
                ':f'  => $firstName,
                ':l'  => $lastName,
                ':d'  => trim($firstName . ' ' . $lastName),
                ':p'  => ($phone === '' ? null : $phone),   // empty → NULL (compatible with the UNIQUE index)
                ':e'  => $email,
                ':r'  => self::normalizeRole($role),
                ':id' => $id,
            ]
        );
        return true;
    }

    /** User edits their own first/last name (without changing username/phone/email/role) */
    public function updateOwnName(int $id, string $firstName, string $lastName): bool
    {
        DB::run(
            'UPDATE users SET first_name = :f, last_name = :l, display_name = :d WHERE id = :id',
            [
                ':f'  => $firstName,
                ':l'  => $lastName,
                ':d'  => trim($firstName . ' ' . $lastName),
                ':id' => $id,
            ]
        );
        return true;
    }

    public function changePassword(int $id, string $newPassword): bool
    {
        DB::run(
            'UPDATE users SET password_hash = :h WHERE id = :id',
            [':h' => password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]), ':id' => $id]
        );
        return true;
    }

    public function toggleActive(int $id): bool
    {
        DB::run(
            'UPDATE users SET is_active = 1 - is_active WHERE id = :id',
            [':id' => $id]
        );
        return true;
    }

    /** Delete user (cascades to tool_access and category_access) */
    public function delete(int $id): bool
    {
        DB::run('DELETE FROM users WHERE id = :id', [':id' => $id]);
        return true;
    }

    // ── Password recovery via email OTP (forgot password) ────────────

    /** Find an active user by email (for the forgot-password flow) */
    public function findActiveByEmail(string $email): ?array
    {
        $row = DB::run(
            'SELECT * FROM users WHERE email = :e AND is_active = 1',
            [':e' => $email]
        )->fetch();
        return $row ?: null;
    }

    /** Store a new OTP code for password reset (replaces the previous code, resets the attempt counter) */
    public function setResetCode(int $id, string $codeHash, int $expires): void
    {
        DB::run(
            'UPDATE users SET reset_code_hash = :c, reset_expires = :x, reset_attempts = 0 WHERE id = :id',
            [':c' => $codeHash, ':x' => $expires, ':id' => $id]
        );
    }

    /** Record one failed attempt at verifying the reset code */
    public function incrementResetAttempts(int $id): void
    {
        DB::run('UPDATE users SET reset_attempts = reset_attempts + 1 WHERE id = :id', [':id' => $id]);
    }

    /** Clear the reset code after successful use */
    public function clearResetCode(int $id): void
    {
        DB::run(
            'UPDATE users SET reset_code_hash = NULL, reset_expires = NULL, reset_attempts = 0 WHERE id = :id',
            [':id' => $id]
        );
    }
}
