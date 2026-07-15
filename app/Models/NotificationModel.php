<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// NotificationModel — all database operations for notifications
// ═══════════════════════════════════════════════════════════

class NotificationModel
{
    // Minimum search term length to use the FULLTEXT index (must match
    // innodb_ft_min_token_size on the MySQL server; shorter terms
    // fall back to LIKE since FULLTEXT never tokenized them at all)
    private const FTS_MIN_TOKEN = 3;

    // Cap on the bell's limited feed — records older than this remain reachable from the history page
    private const BELL_CAP = 100;

    // ── Visibility Queries ──────────────────────────────────

    /**
     * Three-branch UNION subquery for the notifications "accessible" to a user
     * (target_all_users ∪ category-access-matched ∪ tool-access-matched)
     * — instead of a JOIN + OR condition that no index combination could optimize
     * (index merge only works for OR on a single table), each branch is scanned
     * with its own dedicated index (idx_target_created / the category/tool access
     * joins). UNION (not UNION ALL) itself removes duplicate rows between branches
     * since the selected columns for a shared row are exactly identical.
     *
     * The 3rd branch (tool_access) mirrors ToolModel::allForUser(), which already
     * treats direct access to one specific tool as equivalent to category access
     * for card visibility — a user who can see a categorized tool via tool_access
     * alone (no category_access row) should also see notifications targeted at
     * that tool's category.
     *
     * @param string $cols selected columns (n.* or just the needed subset)
     * @param string $uidParam PDO parameter name for user_id, category_access branch (must be unique across calls)
     * @param string $uidParam2 PDO parameter name for user_id, tool_access branch (must be unique across calls)
     * @param int|null $limitPerBranch if set, each branch gets its own LIMIT (the bell's limited feed)
     */
    private function accessibleUnionSql(string $cols, string $uidParam, string $uidParam2, ?int $limitPerBranch = null): string
    {
        $tail = $limitPerBranch !== null
            ? ' ORDER BY n.created_at DESC, n.id DESC LIMIT ' . $limitPerBranch
            : '';
        return "(SELECT {$cols} FROM notifications n WHERE n.target_all_users = 1{$tail})
                 UNION
                 (SELECT {$cols} FROM notifications n
                    JOIN notification_badges nb ON nb.notification_id = n.id
                    JOIN category_access     ca ON ca.category_id = nb.category_id AND ca.user_id = :{$uidParam}
                  {$tail})
                 UNION
                 (SELECT {$cols} FROM notifications n
                    JOIN notification_badges nb ON nb.notification_id = n.id
                    JOIN tools        t  ON t.category_id = nb.category_id
                    JOIN tool_access  ta ON ta.tool_id = t.id AND ta.user_id = :{$uidParam2}
                  {$tail})";
    }

    /**
     * Notifications visible to a logged-in user (the bell's limited feed).
     * Includes: all-users + badge matching the user's access.
     *
     * Capped at the most recent BELL_CAP items at scale (each branch yields up
     * to BELL_CAP candidates, then sorted with unread priority and trimmed back
     * to BELL_CAP) — access to records older than this window is provided by
     * the history page (/notifications, historyForUser), not here.
     */
    public function allActiveForUser(int $userId, bool $isAdmin = false): array
    {
        $now   = time();
        $union = $isAdmin
            ? '(SELECT n.* FROM notifications n ORDER BY n.created_at DESC, n.id DESC LIMIT ' . self::BELL_CAP . ')'
            : $this->accessibleUnionSql('n.*', 'uid1', 'uid3', self::BELL_CAP);
        return DB::run(
            "SELECT u.*,
                    CASE WHEN r.notification_id IS NOT NULL AND r.read_at >= u.updated_at THEN 1 ELSE 0 END AS is_read,
                    CASE WHEN r.notification_id IS NOT NULL AND r.read_at <  u.updated_at THEN 1 ELSE 0 END AS is_edited,
                    CASE WHEN u.expires_at > 0 AND u.expires_at <= :now THEN 1 ELSE 0 END AS is_expired
             FROM ({$union}) u
             LEFT JOIN notification_reads r ON r.notification_id = u.id AND r.user_id = :uid2
             WHERE NOT (
               u.expires_at > 0 AND u.expires_at <= :now2
               AND r.notification_id IS NOT NULL AND r.read_at >= u.updated_at
             )
             ORDER BY is_read ASC, u.created_at DESC, u.id DESC
             LIMIT " . self::BELL_CAP,
            $isAdmin
                ? [':uid2' => $userId, ':now' => $now, ':now2' => $now]
                : [':uid1' => $userId, ':uid2' => $userId, ':uid3' => $userId, ':now' => $now, ':now2' => $now]
        )->fetchAll();
    }

    /**
     * Lightweight version (id/updated_at/is_read) of the user feed — repeats the same
     * selection/cap/sort logic as allActiveForUser with the fewest columns so the fingerprint
     * actually sees every change (new/edited notification, falling out of the BELL_CAP window,
     * or the user's own read-state change) — unlike an approximate signature like
     * MAX(updated_at)+COUNT(*), which doesn't see read-state.
     */
    public function activeUserFingerprint(int $userId, bool $isAdmin = false): array
    {
        $now   = time();
        $union = $isAdmin
            ? '(SELECT n.id, n.created_at, n.updated_at, n.expires_at FROM notifications n ORDER BY n.created_at DESC, n.id DESC LIMIT ' . self::BELL_CAP . ')'
            : $this->accessibleUnionSql('n.id, n.created_at, n.updated_at, n.expires_at', 'uid1', 'uid3', self::BELL_CAP);
        return DB::run(
            "SELECT u.id, u.updated_at,
                    CASE WHEN r.notification_id IS NOT NULL AND r.read_at >= u.updated_at THEN 1 ELSE 0 END AS is_read
             FROM ({$union}) u
             LEFT JOIN notification_reads r ON r.notification_id = u.id AND r.user_id = :uid2
             WHERE NOT (
               u.expires_at > 0 AND u.expires_at <= :now
               AND r.notification_id IS NOT NULL AND r.read_at >= u.updated_at
             )
             ORDER BY is_read ASC, u.created_at DESC, u.id DESC
             LIMIT " . self::BELL_CAP,
            $isAdmin
                ? [':uid2' => $userId, ':now' => $now]
                : [':uid1' => $userId, ':uid2' => $userId, ':uid3' => $userId, ':now' => $now]
        )->fetchAll();
    }

    /**
     * Build the text search clause — only added to the SQL when $search is non-empty
     * (consistent with buildHistoryFilters, which never adds empty filters to the query,
     * so the hot path of "browsing without search" doesn't trigger a table scan).
     *
     * For long-enough terms, the FULLTEXT index is used (BOOLEAN MODE, word-prefix
     * matching); for terms shorter than the server's token minimum, MATCH...AGAINST
     * returns nothing, so we fall back to the previous LIKE. Note: this means search
     * semantics for long terms shift from "arbitrary substring" to "word/word-prefix match".
     */
    /**
     * $suffix: since ATTR_EMULATE_PREPARES=false doesn't allow repeating a parameter name
     * in one query, callers that use this clause multiple times in a single query (e.g. the
     * three UNION branches) must pass a different suffix so the :ftq/:like etc. names stay unique.
     */
    private function buildSearchClause(string $search, array &$params, string $alias = 'n', string $suffix = ''): string
    {
        $search = trim($search);
        if ($search === '') return '';

        if (mb_strlen($search) >= self::FTS_MIN_TOKEN) {
            $params[":ftq{$suffix}"] = $this->buildBooleanQuery($search);
            return " AND MATCH({$alias}.title, {$alias}.body) AGAINST(:ftq{$suffix} IN BOOLEAN MODE)";
        }

        $like = '%' . $search . '%';
        $params[":like{$suffix}"]  = $like;
        $params[":like2{$suffix}"] = $like;
        return " AND ({$alias}.title LIKE :like{$suffix} OR {$alias}.body LIKE :like2{$suffix})";
    }

    /**
     * Convert the user's search term into a safe BOOLEAN MODE query — this mode's
     * special operators (+ - * " ( ) ~ < >) are stripped so the user can't inject
     * an unexpected operator, and each word gets a * suffix (prefix matching)
     * to keep the live-search experience close to the old LIKE behavior.
     */
    private function buildBooleanQuery(string $search): string
    {
        $clean = preg_replace('/[+\-*"()~<>]+/u', ' ', $search);
        $words = array_filter(preg_split('/\s+/u', trim($clean)), static fn($w) => $w !== '');
        $terms = array_map(static fn($w) => $w . '*', $words);
        return implode(' ', $terms);
    }

    /**
     * Build the advanced filter clauses (created date + expiry status).
     * Parameters are added to the $params array and the SQL string is returned.
     * $filters: ['date_from'=>'Y-m-d','date_to'=>'Y-m-d','status'=>'active|expired']
     */
    private function buildHistoryFilters(array $filters, array &$params, string $alias = 'n', string $suffix = ''): string
    {
        $sql = '';
        $df  = trim((string)($filters['date_from'] ?? ''));
        $dt  = trim((string)($filters['date_to']   ?? ''));
        $st  = trim((string)($filters['status']    ?? ''));

        if ($df !== '' && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $df)) {
            $sql .= " AND {$alias}.created_at >= :df{$suffix}";
            $params[":df{$suffix}"] = $df . ' 00:00:00';
        }
        if ($dt !== '' && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $dt)) {
            $sql .= " AND {$alias}.created_at <= :dt{$suffix}";
            $params[":dt{$suffix}"] = $dt . ' 23:59:59';
        }
        if ($st === 'expired') {
            $sql .= " AND {$alias}.expires_at > 0 AND {$alias}.expires_at <= :st_now{$suffix}";
            $params[":st_now{$suffix}"] = time();
        } elseif ($st === 'active') {
            $sql .= " AND ({$alias}.expires_at = 0 OR {$alias}.expires_at > :st_now{$suffix})";
            $params[":st_now{$suffix}"] = time();
        }
        return $sql;
    }

    /**
     * User history with keyset pagination — same per-branch-cap reasoning as historyForUser
     * (a row at position p in a sorted branch can only be in the global result if p<=cap),
     * with a cursor condition added to each branch.
     */
    public function historyForUserKeyset(int $userId, ?array $cursor, string $dir, int $perPage, string $search = '', array $filters = [], bool $isAdmin = false): array
    {
        $perPage = max(1, min(100, $perPage));
        $cap     = $perPage + 1;
        $now     = time();
        $desc    = $dir !== 'prev';

        $params = $isAdmin
            ? [':uid2' => $userId, ':now' => $now]
            : [':uid1' => $userId, ':uid2' => $userId, ':uid3' => $userId, ':now' => $now];
        $s1 = $this->buildSearchClause($search, $params, 'n', '_b1');
        $f1 = $this->buildHistoryFilters($filters, $params, 'n', '_b1');
        $s2 = $s3 = $f2 = $f3 = '';
        if (!$isAdmin) {
            $s2 = $this->buildSearchClause($search, $params, 'n', '_b2');
            $f2 = $this->buildHistoryFilters($filters, $params, 'n', '_b2');
            $s3 = $this->buildSearchClause($search, $params, 'n', '_b3');
            $f3 = $this->buildHistoryFilters($filters, $params, 'n', '_b3');
        }

        $c1 = $c2 = $c3 = '';
        if ($cursor !== null) {
            $cmp = $desc ? '<' : '>';
            $params[':cc1'] = $cursor['created_at']; $params[':ci1'] = $cursor['id'];
            $c1 = " AND (n.created_at, n.id) {$cmp} (:cc1, :ci1)";
            if (!$isAdmin) {
                $params[':cc2'] = $cursor['created_at']; $params[':ci2'] = $cursor['id'];
                $params[':cc3'] = $cursor['created_at']; $params[':ci3'] = $cursor['id'];
                $c2 = " AND (n.created_at, n.id) {$cmp} (:cc2, :ci2)";
                $c3 = " AND (n.created_at, n.id) {$cmp} (:cc3, :ci3)";
            }
        }

        $order = $desc
            ? "ORDER BY n.created_at DESC, n.id DESC LIMIT {$cap}"
            : "ORDER BY n.created_at ASC, n.id ASC LIMIT {$cap}";

        $union = $isAdmin
            ? "(SELECT n.* FROM notifications n WHERE 1=1{$c1}{$s1}{$f1} {$order})"
            : "(SELECT n.* FROM notifications n WHERE n.target_all_users = 1{$c1}{$s1}{$f1} {$order})
                   UNION
                   (SELECT n.* FROM notifications n
                      JOIN notification_badges nb ON nb.notification_id = n.id
                      JOIN category_access     ca ON ca.category_id = nb.category_id AND ca.user_id = :uid1
                    WHERE 1=1{$c2}{$s2}{$f2} {$order})
                   UNION
                   (SELECT n.* FROM notifications n
                      JOIN notification_badges nb ON nb.notification_id = n.id
                      JOIN tools        t  ON t.category_id = nb.category_id
                      JOIN tool_access  ta ON ta.tool_id = t.id AND ta.user_id = :uid3
                    WHERE 1=1{$c3}{$s3}{$f3} {$order})";

        $outerOrder = $desc ? 'ORDER BY u.created_at DESC, u.id DESC' : 'ORDER BY u.created_at ASC, u.id ASC';

        $rows = DB::run(
            "SELECT u.*,
                    CASE WHEN r.notification_id IS NOT NULL AND r.read_at >= u.updated_at THEN 1 ELSE 0 END AS is_read,
                    CASE WHEN r.notification_id IS NOT NULL AND r.read_at <  u.updated_at THEN 1 ELSE 0 END AS is_edited,
                    CASE WHEN u.expires_at > 0 AND u.expires_at <= :now THEN 1 ELSE 0 END AS is_expired
             FROM ({$union}) u
             LEFT JOIN notification_reads r ON r.notification_id = u.id AND r.user_id = :uid2
             {$outerOrder}
             LIMIT {$cap}",
            $params
        )->fetchAll();

        return $desc ? $rows : array_reverse($rows);
    }

    /**
     * Admin list with keyset pagination (the keyset equivalent of allForAdminPaginated, for adjacent Prev/Next arrows)
     */
    public function allForAdminKeyset(?array $cursor, string $dir, int $perPage, string $search = '', array $filters = []): array
    {
        $perPage = max(1, min(100, $perPage));
        $cap     = $perPage + 1;
        $now     = time();
        $desc    = $dir !== 'prev';

        $params    = [':now' => $now];
        $searchSql = $this->buildSearchClause($search, $params);
        $filterSql = $this->buildHistoryFilters($filters, $params);

        $cursorSql = '';
        if ($cursor !== null) {
            $cmp = $desc ? '<' : '>';
            $params[':cc'] = $cursor['created_at'];
            $params[':ci'] = $cursor['id'];
            $cursorSql = " AND (n.created_at, n.id) {$cmp} (:cc, :ci)";
        }
        $order = $desc ? 'ORDER BY n.created_at DESC, n.id DESC' : 'ORDER BY n.created_at ASC, n.id ASC';

        $rows = DB::run(
            "SELECT n.*,
                    CASE WHEN n.expires_at > 0 AND n.expires_at <= :now THEN 1 ELSE 0 END AS is_expired,
                    COALESCE(rc.c, 0) AS read_count
             FROM notifications n
             LEFT JOIN (SELECT notification_id, COUNT(*) c FROM notification_reads GROUP BY notification_id) rc
                    ON rc.notification_id = n.id
             WHERE 1=1{$cursorSql}{$searchSql}{$filterSql}
             {$order}
             LIMIT {$cap}",
            $params
        )->fetchAll();

        return $desc ? $rows : array_reverse($rows);
    }

    /**
     * Paginated history for a logged-in user.
     *
     * Unlike allActiveForUser (which is the bell's limited feed), this method must be able to
     * reach any depth of history; so we don't build the UNION with an overall cap. But computing
     * "the first 10 rows" by building the entire accessible set (which can be tens of thousands
     * of rows) and then applying LIMIT is extremely slow at scale (measured: ~1.7s on 100k rows).
     * Solution: cap each UNION branch with its own filter/search, ORDER BY created_at DESC and
     * LIMIT (offset+perPage) — because a row at position p in a descending sorted list can't be
     * in the global top-(offset+perPage) (the union of several sorted lists) unless p <= offset+perPage.
     * This cap only grows as deep as actually needed (the requested page's OFFSET); for deep
     * pagination (very large page numbers) the cursor/keyset path (phase 3) is used instead,
     * which doesn't depend on this cap at all.
     */
    public function historyForUser(int $userId, int $page, int $perPage, string $search = '', array $filters = [], bool $isAdmin = false): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset  = ($page - 1) * $perPage;
        $now     = time();
        $cap     = $offset + $perPage;

        $params = $isAdmin
            ? [':uid2' => $userId, ':now' => $now]
            : [':uid1' => $userId, ':uid2' => $userId, ':uid3' => $userId, ':now' => $now];
        $s1 = $this->buildSearchClause($search, $params, 'n', '_b1');
        $f1 = $this->buildHistoryFilters($filters, $params, 'n', '_b1');
        $s2 = $s3 = $f2 = $f3 = '';
        if (!$isAdmin) {
            $s2 = $this->buildSearchClause($search, $params, 'n', '_b2');
            $f2 = $this->buildHistoryFilters($filters, $params, 'n', '_b2');
            $s3 = $this->buildSearchClause($search, $params, 'n', '_b3');
            $f3 = $this->buildHistoryFilters($filters, $params, 'n', '_b3');
        }
        $order = "ORDER BY n.created_at DESC, n.id DESC LIMIT {$cap}";

        $union = $isAdmin
            ? "(SELECT n.* FROM notifications n WHERE 1=1{$s1}{$f1} {$order})"
            : "(SELECT n.* FROM notifications n WHERE n.target_all_users = 1{$s1}{$f1} {$order})
                   UNION
                   (SELECT n.* FROM notifications n
                      JOIN notification_badges nb ON nb.notification_id = n.id
                      JOIN category_access     ca ON ca.category_id = nb.category_id AND ca.user_id = :uid1
                    WHERE 1=1{$s2}{$f2} {$order})
                   UNION
                   (SELECT n.* FROM notifications n
                      JOIN notification_badges nb ON nb.notification_id = n.id
                      JOIN tools        t  ON t.category_id = nb.category_id
                      JOIN tool_access  ta ON ta.tool_id = t.id AND ta.user_id = :uid3
                    WHERE 1=1{$s3}{$f3} {$order})";

        return DB::run(
            "SELECT u.*,
                    CASE WHEN r.notification_id IS NOT NULL AND r.read_at >= u.updated_at THEN 1 ELSE 0 END AS is_read,
                    CASE WHEN r.notification_id IS NOT NULL AND r.read_at <  u.updated_at THEN 1 ELSE 0 END AS is_edited,
                    CASE WHEN u.expires_at > 0 AND u.expires_at <= :now THEN 1 ELSE 0 END AS is_expired
             FROM ({$union}) u
             LEFT JOIN notification_reads r ON r.notification_id = u.id AND r.user_id = :uid2
             ORDER BY u.created_at DESC, u.id DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        )->fetchAll();
    }

    public function historyCountForUser(int $userId, string $search = '', array $filters = [], bool $isAdmin = false): int
    {
        // Only the columns needed for counting/filtering (not the full n.*) — a lighter COUNT
        $union  = $isAdmin
            ? '(SELECT n.id, n.title, n.body, n.created_at, n.expires_at FROM notifications n)'
            : $this->accessibleUnionSql('n.id, n.title, n.body, n.created_at, n.expires_at', 'uid1', 'uid2');
        $params = $isAdmin ? [] : [':uid1' => $userId, ':uid2' => $userId];
        $searchSql = $this->buildSearchClause($search, $params, 'u');
        $filterSql = $this->buildHistoryFilters($filters, $params, 'u');

        return (int) DB::run(
            "SELECT COUNT(*) FROM ({$union}) u WHERE 1=1{$searchSql}{$filterSql}",
            $params
        )->fetchColumn();
    }

    // ── Unread Tracking ─────────────────────────────────────

    /**
     * Count of unread notifications for a user.
     *
     * No expiry condition: expired notifications the user hasn't read yet
     * are still counted, so the badge stays until the user reads them,
     * even after expiry.
     */
    public function unreadCount(int $userId, bool $isAdmin = false): int
    {
        $union = $isAdmin
            ? '(SELECT n.id, n.updated_at FROM notifications n)'
            : $this->accessibleUnionSql('n.id, n.updated_at', 'uid1', 'uid3');
        return (int) DB::run(
            "SELECT COUNT(*) FROM (
                SELECT u.id FROM ({$union}) u
                LEFT JOIN notification_reads r ON r.notification_id = u.id AND r.user_id = :uid2
                WHERE r.notification_id IS NULL OR r.read_at < u.updated_at
             ) t",
            $isAdmin
                ? [':uid2' => $userId]
                : [':uid1' => $userId, ':uid2' => $userId, ':uid3' => $userId]
        )->fetchColumn();
    }

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
     * Mark all of a user's accessible notifications as read.
     *
     * No expiry condition: all accessible notifications (including expired ones)
     * are recorded as read so the badge drops fully to zero.
     */
    public function markAllRead(int $userId, bool $isAdmin = false): void
    {
        $union = $isAdmin
            ? '(SELECT n.id FROM notifications n)'
            : $this->accessibleUnionSql('n.id', 'uid2', 'uid3');
        DB::run(
            "INSERT INTO notification_reads (user_id, notification_id)
             SELECT :uid1, u.id FROM ({$union}) u
             ON DUPLICATE KEY UPDATE read_at = CURRENT_TIMESTAMP",
            $isAdmin
                ? [':uid1' => $userId]
                : [':uid1' => $userId, ':uid2' => $userId, ':uid3' => $userId]
        );
    }

    // ── Admin Queries ───────────────────────────────────────

    /** Admin panel notifications with real server-side pagination and optional search */
    public function allForAdminPaginated(int $page, int $perPage, string $search = '', array $filters = []): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset  = ($page - 1) * $perPage;

        $params    = [':now' => time()];
        $searchSql = $this->buildSearchClause($search, $params);
        $filterSql = $this->buildHistoryFilters($filters, $params);

        $limitSql = sprintf('LIMIT %d OFFSET %d', $perPage, $offset);

        return DB::run(
            'SELECT n.*,
                    CASE WHEN n.expires_at > 0 AND n.expires_at <= :now THEN 1 ELSE 0 END AS is_expired,
                    COALESCE(rc.c, 0) AS read_count
             FROM notifications n
             LEFT JOIN (SELECT notification_id, COUNT(*) c FROM notification_reads GROUP BY notification_id) rc
                    ON rc.notification_id = n.id
             WHERE 1=1
             ' . $searchSql . $filterSql . '
             ORDER BY n.created_at DESC, n.id DESC
             ' . $limitSql,
            $params
        )->fetchAll();
    }

    public function countForAdmin(string $search = '', array $filters = []): int
    {
        $params    = [];
        $searchSql = $this->buildSearchClause($search, $params);
        $filterSql = $this->buildHistoryFilters($filters, $params);

        return (int) DB::run(
            'SELECT COUNT(*)
             FROM notifications n
             WHERE 1=1
             ' . $searchSql . $filterSql,
            $params
        )->fetchColumn();
    }

    /**
     * Fetch badges for multiple notifications in one query (avoids the N+1 problem)
     * Output: [notification_id => [badge, badge, ...]]
     */
    public function getBadgesForIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
        if (empty($ids)) return [];

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = DB::run(
            "SELECT nb.notification_id, c.name AS badge
             FROM notification_badges nb
             JOIN categories c ON c.id = nb.category_id
             WHERE nb.notification_id IN ($placeholders)
             ORDER BY c.name ASC",
            $ids
        )->fetchAll();

        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['notification_id']][] = $r['badge'];
        }
        return $map;
    }

    public function findById(int $id): ?array
    {
        $row = DB::run(
            'SELECT * FROM notifications WHERE id = :id',
            [':id' => $id]
        )->fetch();
        return $row ?: null;
    }

    public function getBadges(int $notificationId): array
    {
        return array_column(
            DB::run(
                'SELECT c.name AS badge
                 FROM notification_badges nb
                 JOIN categories c ON c.id = nb.category_id
                 WHERE nb.notification_id = :nid
                 ORDER BY c.name ASC',
                [':nid' => $notificationId]
            )->fetchAll(),
            'badge'
        );
    }

    /**
     * Users who have read a given notification, most recent first — for the admin "read by" view.
     * Paginated (limit/offset) so a widely-read notification doesn't load thousands of rows at once.
     */
    public function getReaders(int $notificationId, int $limit = 50, int $offset = 0): array
    {
        $limit  = max(1, min(200, $limit));
        $offset = max(0, $offset);
        return DB::run(
            "SELECT u.id, u.username, u.display_name, r.read_at
             FROM notification_reads r
             JOIN users u ON u.id = r.user_id
             WHERE r.notification_id = :nid
             ORDER BY r.read_at DESC
             LIMIT {$limit} OFFSET {$offset}",
            [':nid' => $notificationId]
        )->fetchAll();
    }

    /** Total number of users who have read a given notification (for the "N نفر" summary + load-more) */
    public function getReaderCount(int $notificationId): int
    {
        return (int) DB::run(
            'SELECT COUNT(*) FROM notification_reads WHERE notification_id = :nid',
            [':nid' => $notificationId]
        )->fetchColumn();
    }

    // ── Admin Write Operations ──────────────────────────────

    /** Create a new notification — returns the created ID */
    public function create(array $data): int
    {
        DB::run(
            'INSERT INTO notifications (title, body, image_path, thumbnail_path, target_all_users, expires_at)
             VALUES (:title, :body, :image_path, :thumbnail_path, :target_all_users, :expires_at)',
            [
                ':title'            => $data['title']            ?? '',
                ':body'             => $data['body']             ?? null,
                ':image_path'       => $data['image_path']       ?? null,
                ':thumbnail_path'   => $data['thumbnail_path']   ?? null,
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

    public function update(int $id, array $data): bool
    {
        // If image_path isn't in data, leave the image untouched
        $hasImage = array_key_exists('image_path', $data);

        if ($hasImage) {
            DB::run(
                'UPDATE notifications
                 SET title = :title, body = :body, image_path = :image_path,
                     thumbnail_path = :thumbnail_path,
                     target_all_users = :target_all_users,
                     expires_at = :expires_at,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id',
                [
                    ':title'            => $data['title']            ?? '',
                    ':body'             => $data['body']             ?? null,
                    ':image_path'       => $data['image_path'],
                    ':thumbnail_path'   => $data['thumbnail_path']   ?? null,
                    ':target_all_users' => (int) ($data['target_all_users'] ?? 0),
                    ':expires_at'       => (int) ($data['expires_at']       ?? 0),
                    ':id'               => $id,
                ]
            );
        } else {
            DB::run(
                'UPDATE notifications
                 SET title = :title, body = :body,
                     target_all_users = :target_all_users,
                     expires_at = :expires_at,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id',
                [
                    ':title'            => $data['title']            ?? '',
                    ':body'             => $data['body']             ?? null,
                    ':target_all_users' => (int) ($data['target_all_users'] ?? 0),
                    ':expires_at'       => (int) ($data['expires_at']       ?? 0),
                    ':id'               => $id,
                ]
            );
        }

        $this->setBadges($id, $data['badges'] ?? []);

        return true;
    }

    /** Delete a notification (cascades to badges and reads) */
    public function delete(int $id): bool
    {
        DB::run('DELETE FROM notifications WHERE id = :id', [':id' => $id]);
        return true;
    }

    /** Clear a notification's image (DB path only — file deletion happens in the Controller) */
    public function clearImage(int $id): void
    {
        DB::run(
            'UPDATE notifications SET image_path = NULL, thumbnail_path = NULL WHERE id = :id',
            [':id' => $id]
        );
    }

    // ── Badge Management ────────────────────────────────────

    /** Fully rewrite a notification's target badges */
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
            'INSERT IGNORE INTO notification_badges (notification_id, category_id) VALUES (:nid, :category_id)'
        );

        // Only record valid badges (existing in tools) — resolved to category_id
        $categoryModel = new CategoryModel();
        foreach ($badges as $badge) {
            $categoryId = $categoryModel->findIdByName((string) $badge);
            if ($categoryId !== null) {
                $stmt->execute([':nid' => $notificationId, ':category_id' => $categoryId]);
            }
        }
    }

    // ── Helpers ─────────────────────────────────────────────

    /** Convert a DB row to the format sent to the frontend */
    public static function toFrontend(array $row, array $badges = []): array
    {
        return [
            'id'               => (int)  $row['id'],
            'title'            => $row['title'],
            'body'             => $row['body']            ?? '',
            'image_path'       => $row['image_path']      ?? null,
            'thumbnail_path'   => $row['thumbnail_path']  ?? null,
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