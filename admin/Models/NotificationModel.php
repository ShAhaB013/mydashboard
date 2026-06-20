<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// NotificationModel — تمام عملیات دیتابیس برای اعلان‌ها
// ═══════════════════════════════════════════════════════════

class NotificationModel
{
    // ── Visibility Queries ──────────────────────────────────

    /**
     * اعلان‌های قابل نمایش برای بازدیدکننده مهمان
     * همه عمومی‌ها (فعال + منقضی) برمی‌گردند با flag is_expired —
     * فرانت‌اند، منقضی‌شده‌های خوانده‌شده را از لیست حذف می‌کند
     * تا badge برای منقضی‌شده‌های ناخوانده حفظ شود.
     */
    public function allForGuest(): array
    {
        $now = time();
        return DB::run(
            'SELECT n.*,
                    CASE WHEN n.expires_at > 0 AND n.expires_at <= :now THEN 1 ELSE 0 END AS is_expired
             FROM notifications n
             WHERE n.is_public = 1
             ORDER BY n.created_at DESC',
            [':now' => $now]
        )->fetchAll();
    }

    /**
     * اعلان‌های قابل نمایش برای کاربر لاگین‌کرده (فید فعال)
     * شامل: عمومی + همه کاربران + badge مطابق دسترسی کاربر
     *
     * منطق نمایش: یا اعلان فعال است، یا منقضی‌شده ولی این کاربر آن را
     * نخوانده — تا badge تا زمان خواندن باقی بماند و کاربر بتواند آن را
     * در پنل bell باز کند.
     */
    public function allActiveForUser(int $userId): array
    {
        $now = time();
        return DB::run(
            'SELECT DISTINCT n.*,
                    CASE WHEN r.notification_id IS NOT NULL AND r.read_at >= n.updated_at THEN 1 ELSE 0 END AS is_read,
                    CASE WHEN r.notification_id IS NOT NULL AND r.read_at <  n.updated_at THEN 1 ELSE 0 END AS is_edited,
                    CASE WHEN n.expires_at > 0 AND n.expires_at <= :now THEN 1 ELSE 0 END AS is_expired
             FROM notifications n
             LEFT JOIN notification_badges nb ON nb.notification_id = n.id
             LEFT JOIN category_access     ca ON ca.badge = nb.badge AND ca.user_id = :uid2
             LEFT JOIN notification_reads   r  ON r.notification_id = n.id AND r.user_id = :uid3
             WHERE (
                     (n.expires_at = 0 OR n.expires_at > :now2)
                     OR (r.notification_id IS NULL OR r.read_at < n.updated_at)
                   )
               AND (
                     n.is_public        = 1
                     OR n.target_all_users = 1
                     OR ca.user_id      IS NOT NULL
                   )
             ORDER BY n.created_at DESC',
            [':uid2' => $userId, ':uid3' => $userId, ':now' => $now, ':now2' => $now]
        )->fetchAll();
    }

    /**
     * تاریخچه اعلان‌های عمومی برای مهمان — با صفحه‌بندی و جستجو
     * شامل اعلان‌های منقضی‌شده هم می‌شود (تاریخچه کامل)
     */
    /**
     * ساخت شرط‌های جستجوی پیشرفته (تاریخ ایجاد + وضعیت انقضا).
     * پارامترها به آرایه $params اضافه می‌شوند و رشته SQL برگردانده می‌شود.
     * $filters: ['date_from'=>'Y-m-d','date_to'=>'Y-m-d','status'=>'active|expired']
     */
    private function buildHistoryFilters(array $filters, array &$params): string
    {
        $sql = '';
        $df  = trim((string)($filters['date_from'] ?? ''));
        $dt  = trim((string)($filters['date_to']   ?? ''));
        $st  = trim((string)($filters['status']    ?? ''));

        if ($df !== '' && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $df)) {
            $sql .= ' AND n.created_at >= :df';
            $params[':df'] = $df . ' 00:00:00';
        }
        if ($dt !== '' && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $dt)) {
            $sql .= ' AND n.created_at <= :dt';
            $params[':dt'] = $dt . ' 23:59:59';
        }
        if ($st === 'expired') {
            $sql .= ' AND n.expires_at > 0 AND n.expires_at <= :st_now';
            $params[':st_now'] = time();
        } elseif ($st === 'active') {
            $sql .= ' AND (n.expires_at = 0 OR n.expires_at > :st_now)';
            $params[':st_now'] = time();
        }
        return $sql;
    }

    public function historyForGuest(int $page, int $perPage, string $search = '', array $filters = []): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset  = ($page - 1) * $perPage;
        $like    = '%' . $search . '%';
        $now     = time();

        $params = [
            ':now'    => $now,
            ':search' => $search,
            ':like'   => $like,
            ':like2'  => $like,
        ];
        $filterSql = $this->buildHistoryFilters($filters, $params);

        // LIMIT/OFFSET به‌صورت عدد صحیح اعتبارسنجی‌شده مستقیم در کوئری تزریق می‌شوند
        $limitSql = sprintf('LIMIT %d OFFSET %d', $perPage, $offset);

        return DB::run(
            'SELECT n.*,
                    CASE WHEN n.expires_at > 0 AND n.expires_at <= :now THEN 1 ELSE 0 END AS is_expired
             FROM notifications n
             WHERE n.is_public = 1
               AND (:search = \'\' OR n.title LIKE :like OR n.body LIKE :like2)
             ' . $filterSql . '
             ORDER BY n.created_at DESC, n.id DESC
             ' . $limitSql,
            $params
        )->fetchAll();
    }

    /**
     * تعداد کل اعلان‌های عمومی برای صفحه‌بندی مهمان
     */
    public function historyCountForGuest(string $search = '', array $filters = []): int
    {
        $like = '%' . $search . '%';

        $params = [
            ':search' => $search,
            ':like'   => $like,
            ':like2'  => $like,
        ];
        $filterSql = $this->buildHistoryFilters($filters, $params);

        return (int) DB::run(
            'SELECT COUNT(*)
             FROM notifications n
             WHERE n.is_public = 1
               AND (:search = \'\' OR n.title LIKE :like OR n.body LIKE :like2)
             ' . $filterSql,
            $params
        )->fetchColumn();
    }

    /**
     * تاریخچه اعلان‌ها برای کاربر (شامل منقضی‌شده‌ها) با صفحه‌بندی
     * جهت صفحه notifications.php
     */
    public function historyForUser(int $userId, int $page, int $perPage, string $search = '', array $filters = []): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset  = ($page - 1) * $perPage;
        $like    = '%' . $search . '%';

        $params = [
            ':now2'   => time(),
            ':uid2'   => $userId,
            ':uid3'   => $userId,
            ':search' => $search,
            ':like'   => $like,
            ':like2'  => $like,
        ];
        $filterSql = $this->buildHistoryFilters($filters, $params);

        $limitSql = sprintf('LIMIT %d OFFSET %d', $perPage, $offset);

        return DB::run(
            'SELECT DISTINCT n.*,
                    CASE WHEN r.notification_id IS NOT NULL AND r.read_at >= n.updated_at THEN 1 ELSE 0 END AS is_read,
                    CASE WHEN r.notification_id IS NOT NULL AND r.read_at <  n.updated_at THEN 1 ELSE 0 END AS is_edited,
                    CASE WHEN n.expires_at > 0 AND n.expires_at <= :now2 THEN 1 ELSE 0 END AS is_expired
             FROM notifications n
             LEFT JOIN notification_badges nb ON nb.notification_id = n.id
             LEFT JOIN category_access     ca ON ca.badge = nb.badge AND ca.user_id = :uid2
             LEFT JOIN notification_reads   r  ON r.notification_id = n.id AND r.user_id = :uid3
             WHERE (
                   n.is_public        = 1
                   OR n.target_all_users = 1
                   OR ca.user_id      IS NOT NULL
               )
               AND (:search = \'\' OR n.title LIKE :like OR n.body LIKE :like2)
             ' . $filterSql . '
             ORDER BY n.created_at DESC, n.id DESC
             ' . $limitSql,
            $params
        )->fetchAll();
    }

    /**
     * تعداد کل برای صفحه‌بندی تاریخچه
     */
    public function historyCountForUser(int $userId, string $search = '', array $filters = []): int
    {
        $like = '%' . $search . '%';

        $params = [
            ':uid2'   => $userId,
            ':search' => $search,
            ':like'   => $like,
            ':like2'  => $like,
        ];
        $filterSql = $this->buildHistoryFilters($filters, $params);

        return (int) DB::run(
            'SELECT COUNT(DISTINCT n.id)
             FROM notifications n
             LEFT JOIN notification_badges nb ON nb.notification_id = n.id
             LEFT JOIN category_access     ca ON ca.badge = nb.badge AND ca.user_id = :uid2
             WHERE (
                   n.is_public        = 1
                   OR n.target_all_users = 1
                   OR ca.user_id      IS NOT NULL
               )
               AND (:search = \'\' OR n.title LIKE :like OR n.body LIKE :like2)
             ' . $filterSql,
            $params
        )->fetchColumn();
    }

    // ── Unread Tracking ─────────────────────────────────────

    /**
     * تعداد اعلان‌های خوانده‌نشده برای کاربر
     *
     * شرط انقضا حذف شده: اعلان‌های منقضی‌شده‌ای که هنوز این کاربر آن‌ها
     * را نخوانده نیز شمرده می‌شوند، تا badge تا زمان خوانده شدن باقی
     * بماند حتی پس از انقضا.
     */
    public function unreadCount(int $userId): int
    {
        return (int) DB::run(
            'SELECT COUNT(DISTINCT n.id)
             FROM notifications n
             LEFT JOIN notification_badges nb ON nb.notification_id = n.id
             LEFT JOIN category_access     ca ON ca.badge = nb.badge AND ca.user_id = :uid2
             LEFT JOIN notification_reads   r  ON r.notification_id = n.id AND r.user_id = :uid3
             WHERE (r.notification_id IS NULL OR r.read_at < n.updated_at)
               AND (
                   n.is_public        = 1
                   OR n.target_all_users = 1
                   OR ca.user_id      IS NOT NULL
               )',
            [':uid2' => $userId, ':uid3' => $userId]
        )->fetchColumn();
    }

    /**
     * علامت‌گذاری یک اعلان به عنوان خوانده‌شده
     */
    public function markRead(int $userId, int $notificationId): void
    {
        DB::run(
            'INSERT INTO notification_reads (user_id, notification_id)
             VALUES (:uid, :nid)
             ON DUPLICATE KEY UPDATE read_at = CURRENT_TIMESTAMP',
            [':uid' => $userId, ':nid' => $notificationId]
        );
    }

    /**
     * علامت‌گذاری همه اعلان‌های قابل دسترس کاربر به عنوان خوانده‌شده
     *
     * شرط انقضا حذف شده: همه اعلان‌های قابل دسترس (شامل منقضی‌شده‌ها)
     * به‌عنوان خوانده‌شده ثبت می‌شوند تا badge کاملا صفر شود.
     */
    public function markAllRead(int $userId): void
    {
        DB::run(
            'INSERT INTO notification_reads (user_id, notification_id)
             SELECT DISTINCT :uid, n.id
             FROM notifications n
             LEFT JOIN notification_badges nb ON nb.notification_id = n.id
             LEFT JOIN category_access     ca ON ca.badge = nb.badge AND ca.user_id = :uid2
             WHERE (
                   n.is_public        = 1
                   OR n.target_all_users = 1
                   OR ca.user_id      IS NOT NULL
               )
             ON DUPLICATE KEY UPDATE read_at = CURRENT_TIMESTAMP',
            [':uid' => $userId, ':uid2' => $userId]
        );
    }

    // ── Admin Queries ───────────────────────────────────────

    /**
     * همه اعلان‌ها برای پنل ادمین (با تعداد خوانده‌شده)
     * توجه: برای دیتاست بزرگ از allForAdminPaginated استفاده کنید.
     */
    public function allForAdmin(): array
    {
        return DB::run(
            'SELECT n.*,
                    CASE WHEN n.expires_at > 0 AND n.expires_at <= :now THEN 1 ELSE 0 END AS is_expired
             FROM notifications n
             ORDER BY n.created_at DESC, n.id DESC',
            [':now' => time()]
        )->fetchAll();
    }

    /**
     * اعلان‌های پنل ادمین با صفحه‌بندی واقعی سمت سرور و جستجوی اختیاری
     */
    public function allForAdminPaginated(int $page, int $perPage, string $search = '', array $filters = []): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset  = ($page - 1) * $perPage;
        $like    = '%' . $search . '%';

        $params = [
            ':now'    => time(),
            ':search' => $search,
            ':like'   => $like,
            ':like2'  => $like,
        ];
        $filterSql = $this->buildHistoryFilters($filters, $params);

        $limitSql = sprintf('LIMIT %d OFFSET %d', $perPage, $offset);

        return DB::run(
            'SELECT n.*,
                    CASE WHEN n.expires_at > 0 AND n.expires_at <= :now THEN 1 ELSE 0 END AS is_expired
             FROM notifications n
             WHERE (:search = \'\' OR n.title LIKE :like OR n.body LIKE :like2)
             ' . $filterSql . '
             ORDER BY n.created_at DESC, n.id DESC
             ' . $limitSql,
            $params
        )->fetchAll();
    }

    /**
     * تعداد کل اعلان‌ها برای صفحه‌بندی ادمین (با جستجوی اختیاری)
     */
    public function countForAdmin(string $search = '', array $filters = []): int
    {
        $like = '%' . $search . '%';

        $params = [
            ':search' => $search,
            ':like'   => $like,
            ':like2'  => $like,
        ];
        $filterSql = $this->buildHistoryFilters($filters, $params);

        return (int) DB::run(
            'SELECT COUNT(*)
             FROM notifications n
             WHERE (:search = \'\' OR n.title LIKE :like OR n.body LIKE :like2)
             ' . $filterSql,
            $params
        )->fetchColumn();
    }

    /**
     * دریافت badgeهای چند اعلان در یک کوئری (رفع مشکل N+1)
     * خروجی: [notification_id => [badge, badge, ...]]
     */
    public function getBadgesForIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
        if (empty($ids)) return [];

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = DB::run(
            "SELECT notification_id, badge
             FROM notification_badges
             WHERE notification_id IN ($placeholders)
             ORDER BY badge ASC",
            $ids
        )->fetchAll();

        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['notification_id']][] = $r['badge'];
        }
        return $map;
    }

    /**
     * یافتن اعلان با ID
     */
    public function findById(int $id): ?array
    {
        $row = DB::run(
            'SELECT * FROM notifications WHERE id = :id',
            [':id' => $id]
        )->fetch();
        return $row ?: null;
    }

    /**
     * دریافت badge های هدف یک اعلان
     */
    public function getBadges(int $notificationId): array
    {
        return array_column(
            DB::run(
                'SELECT badge FROM notification_badges WHERE notification_id = :nid ORDER BY badge ASC',
                [':nid' => $notificationId]
            )->fetchAll(),
            'badge'
        );
    }

    // ── Admin Write Operations ──────────────────────────────

    /**
     * ایجاد اعلان جدید — برگرداندن ID ایجادشده
     */
    public function create(array $data): int
    {
        DB::run(
            'INSERT INTO notifications (title, body, image_path, thumbnail_path, is_public, target_all_users, expires_at)
             VALUES (:title, :body, :image_path, :thumbnail_path, :is_public, :target_all_users, :expires_at)',
            [
                ':title'            => $data['title']            ?? '',
                ':body'             => $data['body']             ?? null,
                ':image_path'       => $data['image_path']       ?? null,
                ':thumbnail_path'   => $data['thumbnail_path']   ?? null,
                ':is_public'        => (int) ($data['is_public']        ?? 0),
                ':target_all_users' => (int) ($data['target_all_users'] ?? 0),
                ':expires_at'       => (int) ($data['expires_at']       ?? 0),
            ]
        );

        $id = (int) DB::get()->lastInsertId();

        if (!empty($data['badges'])) {
            $this->setBadges($id, $data['badges']);
        }

        return $id;
    }

    /**
     * ویرایش اعلان موجود
     */
    public function update(int $id, array $data): bool
    {
        // اگه image_path در data نباشه، تصویر را دست‌نخورده بذار
        $hasImage = array_key_exists('image_path', $data);

        if ($hasImage) {
            DB::run(
                'UPDATE notifications
                 SET title = :title, body = :body, image_path = :image_path,
                     thumbnail_path = :thumbnail_path,
                     is_public = :is_public, target_all_users = :target_all_users,
                     expires_at = :expires_at,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id',
                [
                    ':title'            => $data['title']            ?? '',
                    ':body'             => $data['body']             ?? null,
                    ':image_path'       => $data['image_path'],
                    ':thumbnail_path'   => $data['thumbnail_path']   ?? null,
                    ':is_public'        => (int) ($data['is_public']        ?? 0),
                    ':target_all_users' => (int) ($data['target_all_users'] ?? 0),
                    ':expires_at'       => (int) ($data['expires_at']       ?? 0),
                    ':id'               => $id,
                ]
            );
        } else {
            DB::run(
                'UPDATE notifications
                 SET title = :title, body = :body,
                     is_public = :is_public, target_all_users = :target_all_users,
                     expires_at = :expires_at,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id',
                [
                    ':title'            => $data['title']            ?? '',
                    ':body'             => $data['body']             ?? null,
                    ':is_public'        => (int) ($data['is_public']        ?? 0),
                    ':target_all_users' => (int) ($data['target_all_users'] ?? 0),
                    ':expires_at'       => (int) ($data['expires_at']       ?? 0),
                    ':id'               => $id,
                ]
            );
        }

        // بازنویسی badge های هدف
        $this->setBadges($id, $data['badges'] ?? []);

        return true;
    }

    /**
     * حذف اعلان (cascade روی badge ها و reads)
     */
    public function delete(int $id): bool
    {
        DB::run('DELETE FROM notifications WHERE id = :id', [':id' => $id]);
        return true;
    }

    /**
     * حذف تصویر یک اعلان (فقط مسیر DB — حذف فایل در Controller)
     */
    public function clearImage(int $id): void
    {
        DB::run(
            'UPDATE notifications SET image_path = NULL, thumbnail_path = NULL WHERE id = :id',
            [':id' => $id]
        );
    }

    // ── Badge Management ────────────────────────────────────

    /**
     * بازنویسی کامل badge های هدف یک اعلان
     */
    private function setBadges(int $notificationId, array $badges): void
    {
        DB::run(
            'DELETE FROM notification_badges WHERE notification_id = :nid',
            [':nid' => $notificationId]
        );

        if (empty($badges)) {
            return;
        }

        $stmt = DB::get()->prepare(
            'INSERT IGNORE INTO notification_badges (notification_id, badge) VALUES (:nid, :badge)'
        );

        // فقط badge های معتبر (موجود در tools) را ثبت کن
        $validBadges = $this->getAvailableBadges();
        foreach ($badges as $badge) {
            $badge = (string) $badge;
            if ($badge !== '' && in_array($badge, $validBadges, true)) {
                $stmt->execute([':nid' => $notificationId, ':badge' => $badge]);
            }
        }
    }

    /**
     * لیست badge های موجود در سیستم (از جدول tools)
     */
    public function getAvailableBadges(): array
    {
        return array_column(
            DB::run(
                "SELECT DISTINCT badge FROM tools WHERE badge != '' ORDER BY badge ASC"
            )->fetchAll(),
            'badge'
        );
    }

    // ── Helpers ─────────────────────────────────────────────

    /**
     * تبدیل ردیف DB به فرمت قابل ارسال به فرانت‌اند
     */
    public static function toFrontend(array $row, array $badges = []): array
    {
        return [
            'id'               => (int)  $row['id'],
            'title'            => $row['title'],
            'body'             => $row['body']            ?? '',
            'image_path'       => $row['image_path']      ?? null,
            'thumbnail_path'   => $row['thumbnail_path']  ?? null,
            'is_public'        => (bool) $row['is_public'],
            'target_all_users' => (bool) $row['target_all_users'],
            'expires_at'       => (int)  $row['expires_at'],
            'created_at'       => $row['created_at'],
            'updated_at'       => $row['updated_at'] ?? null,
            'badges'           => $badges,
            'is_read'          => isset($row['is_read'])    ? (bool) $row['is_read']    : false,
            'is_edited'        => isset($row['is_edited'])  ? (bool) $row['is_edited']  : false,
            'is_expired'       => isset($row['is_expired']) ? (bool) $row['is_expired'] : false,
            'read_count'       => isset($row['read_count']) ? (int)  $row['read_count'] : 0,
        ];
    }
}