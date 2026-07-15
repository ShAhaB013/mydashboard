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

    // ── Fan-out (notification_recipients) ───────────────────
    //
    // "Who can see what" (target_all_users ∪ category_access ∪ tool_access) used to be
    // computed live, via a 3-branch UNION, on every single read (every bell poll, every
    // history page load, for every user). That's a fan-out-on-read design applied to a
    // fan-out-on-write-shaped workload: notifications are created rarely (an admin action)
    // but read constantly (every active user, every ~25s poll) — so the UNION's cost was
    // paid far more often than necessary, and was the actual bottleneck under load (temp
    // disk tables from the joined TEXT `body` column, no usable index-merge for the OR).
    //
    // notification_recipients materializes that resolution once, at write time, into a
    // plain (user_id, notification_id) table. Reads become a single indexed lookup —
    // no UNION, no per-branch cap, no arbitrary "bell shows at most N items" limitation.
    // The 3-way resolution logic itself doesn't disappear — it just runs once per write
    // (refreshRecipientsForNotification/refreshRecipientsForUser below) instead of once
    // per read. See migrations/003_notification_recipients_fanout.sql for the schema and
    // the one-time backfill of pre-existing notifications, and
    // migrations/rebuild_notification_recipients.php for drift repair (this host has
    // phpMyAdmin but no SSH/CLI on production, so direct out-of-band DB edits are a real
    // possibility, not just a theoretical one).

    /**
     * Full recompute of who can see ONE notification — call after creating/updating a
     * notification or changing its badges (target_all_users flag or category set).
     * Deletes then re-resolves that notification's rows so it's always correct after
     * partial updates, not just inserts.
     */
    public function refreshRecipientsForNotification(int $notificationId): void
    {
        DB::run('DELETE FROM notification_recipients WHERE notification_id = :nid', [':nid' => $notificationId]);

        $notif = DB::run(
            'SELECT target_all_users, created_at FROM notifications WHERE id = :nid',
            [':nid' => $notificationId]
        )->fetch();
        if (!$notif) return; // deleted concurrently — nothing to resolve

        if ((int) $notif['target_all_users'] === 1) {
            DB::run(
                'INSERT INTO notification_recipients (notification_id, user_id, created_at)
                 SELECT :nid, u.id, :created_at FROM users u',
                [':nid' => $notificationId, ':created_at' => $notif['created_at']]
            );
            return;
        }

        DB::run(
            'INSERT INTO notification_recipients (notification_id, user_id, created_at)
             SELECT :nid1, x.user_id, :created_at FROM (
                 SELECT ca.user_id FROM category_access ca
                   JOIN notification_badges nb ON nb.category_id = ca.category_id
                  WHERE nb.notification_id = :nid2
                 UNION
                 SELECT ta.user_id FROM tool_access ta
                   JOIN tools t ON t.id = ta.tool_id
                   JOIN notification_badges nb2 ON nb2.category_id = t.category_id
                  WHERE nb2.notification_id = :nid3
             ) x',
            [
                ':nid1' => $notificationId, ':created_at' => $notif['created_at'],
                ':nid2' => $notificationId, ':nid3' => $notificationId,
            ]
        );
    }

    /**
     * Full recompute of what ONE user can see — call after their category_access/
     * tool_access changes, after a new user is created (to seed target_all_users rows —
     * those apply regardless of account age, same as the old live query had no
     * user-creation-date filter), or after a tool's category/access changes affect
     * whichever users hold tool_access to it.
     */
    public function refreshRecipientsForUser(int $userId): void
    {
        DB::run('DELETE FROM notification_recipients WHERE user_id = :uid', [':uid' => $userId]);

        DB::run(
            'INSERT INTO notification_recipients (notification_id, user_id, created_at)
             SELECT n.id, :uid1, n.created_at FROM notifications n WHERE n.target_all_users = 1
             UNION
             SELECT n.id, :uid2, n.created_at FROM notifications n
               JOIN notification_badges nb ON nb.notification_id = n.id
               JOIN category_access ca ON ca.category_id = nb.category_id AND ca.user_id = :uid3
             UNION
             SELECT n.id, :uid4, n.created_at FROM notifications n
               JOIN notification_badges nb2 ON nb2.notification_id = n.id
               JOIN tools t ON t.category_id = nb2.category_id
               JOIN tool_access ta ON ta.tool_id = t.id AND ta.user_id = :uid5',
            [':uid1' => $userId, ':uid2' => $userId, ':uid3' => $userId, ':uid4' => $userId, ':uid5' => $userId]
        );
    }

    // ── Visibility Queries ──────────────────────────────────

    /**
     * Notifications visible to a logged-in user (the bell panel), keyset-paginated —
     * no cap, no unread-first reordering (a sort key that mutates as items get read is
     * fundamentally incompatible with stable keyset pagination; unread is shown as a
     * per-row marker client-side instead). Ordered purely by (created_at, id) DESC, same
     * convention as historyForUserKeyset, so the same cursor logic applies to both.
     *
     * @param array{created_at:string,id:int}|null $cursor
     * @return array{rows:array,has_more:bool}
     */
    public function bellFeed(int $userId, bool $isAdmin, ?array $cursor, int $perPage): array
    {
        $perPage = max(1, min(100, $perPage));
        $cap     = $perPage + 1;
        $now     = time();

        // :now is needed twice in the query text (the is_expired column + the expired-and-read
        // guard below) — ATTR_EMULATE_PREPARES=false (real server-side prepares) doesn't allow
        // reusing one named placeholder twice, so each occurrence gets its own bound copy.
        $params    = [':now' => $now, ':now2' => $now, ':uid' => $userId];
        $cursorSql = '';
        if ($cursor !== null) {
            $params[':cc'] = $cursor['created_at'];
            $params[':ci'] = $cursor['id'];
            $cursorSql = ' AND (nr.created_at, n.id) < (:cc, :ci)';
        }

        $expiredReadGuard = 'NOT (
               n.expires_at > 0 AND n.expires_at <= :now2
               AND r.notification_id IS NOT NULL AND r.read_at >= n.updated_at
             )';

        if ($isAdmin) {
            $cursorSql = str_replace('nr.created_at', 'n.created_at', $cursorSql);
            $rows = DB::run(
                "SELECT n.*,
                        CASE WHEN r.notification_id IS NOT NULL AND r.read_at >= n.updated_at THEN 1 ELSE 0 END AS is_read,
                        CASE WHEN r.notification_id IS NOT NULL AND r.read_at <  n.updated_at THEN 1 ELSE 0 END AS is_edited,
                        CASE WHEN n.expires_at > 0 AND n.expires_at <= :now THEN 1 ELSE 0 END AS is_expired
                 FROM notifications n
                 LEFT JOIN notification_reads r ON r.notification_id = n.id AND r.user_id = :uid
                 WHERE {$expiredReadGuard}{$cursorSql}
                 ORDER BY n.created_at DESC, n.id DESC
                 LIMIT {$cap}",
                $params
            )->fetchAll();
        } else {
            $params[':uid2'] = $userId;
            $rows = DB::run(
                "SELECT n.*,
                        CASE WHEN r.notification_id IS NOT NULL AND r.read_at >= n.updated_at THEN 1 ELSE 0 END AS is_read,
                        CASE WHEN r.notification_id IS NOT NULL AND r.read_at <  n.updated_at THEN 1 ELSE 0 END AS is_edited,
                        CASE WHEN n.expires_at > 0 AND n.expires_at <= :now THEN 1 ELSE 0 END AS is_expired
                 FROM notification_recipients nr
                 JOIN notifications n ON n.id = nr.notification_id
                 LEFT JOIN notification_reads r ON r.notification_id = n.id AND r.user_id = :uid
                 WHERE nr.user_id = :uid2 AND {$expiredReadGuard}{$cursorSql}
                 ORDER BY nr.created_at DESC, n.id DESC
                 LIMIT {$cap}",
                $params
            )->fetchAll();
        }

        $hasMore = count($rows) > $perPage;
        return ['rows' => array_slice($rows, 0, $perPage), 'has_more' => $hasMore];
    }

    /**
     * O(1) change-detection watermark for the bell's poll — replaces the old fingerprint,
     * which had to scan up to BELL_CAP rows (via the live UNION) just to hash "did anything
     * change". latest+total+unread are each a single indexed lookup; any real state change
     * a client needs to react to (new notification, edited notification, gained/lost access,
     * deleted notification, own read-state change) moves at least one of the three.
     */
    public function bellWatermark(int $userId, bool $isAdmin): array
    {
        if ($isAdmin) {
            $row = DB::run(
                'SELECT MAX(GREATEST(created_at, updated_at)) AS latest, COUNT(*) AS total FROM notifications'
            )->fetch();
        } else {
            $row = DB::run(
                'SELECT MAX(GREATEST(n.created_at, n.updated_at)) AS latest, COUNT(*) AS total
                 FROM notification_recipients nr
                 JOIN notifications n ON n.id = nr.notification_id
                 WHERE nr.user_id = :uid',
                [':uid' => $userId]
            )->fetch();
        }
        return [
            'latest' => $row['latest'] ?? null,
            'total'  => (int) ($row['total'] ?? 0),
            'unread' => $this->unreadCount($userId, $isAdmin),
        ];
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
     * User history with keyset pagination. A single indexed scan over notification_recipients
     * joined to notifications (via notification_recipients for non-admins) — the old version
     * needed a 3-branch UNION each capped at offset+perPage; with the fan-out table there's
     * only one table to scan, so that per-branch-cap trick is no longer needed at all.
     */
    public function historyForUserKeyset(int $userId, ?array $cursor, string $dir, int $perPage, string $search = '', array $filters = [], bool $isAdmin = false): array
    {
        $perPage = max(1, min(100, $perPage));
        $cap     = $perPage + 1;
        $now     = time();
        $desc    = $dir !== 'prev';
        $cmp     = $desc ? '<' : '>';

        $params    = [':now' => $now, ':uid' => $userId];
        $searchSql = $this->buildSearchClause($search, $params);
        $filterSql = $this->buildHistoryFilters($filters, $params);

        $scopeSql = '';
        if (!$isAdmin) {
            $params[':uid2'] = $userId;
            $scopeSql = ' AND nr.user_id = :uid2';
        }
        if ($cursor !== null) {
            $params[':cc'] = $cursor['created_at'];
            $params[':ci'] = $cursor['id'];
            $scopeSql .= " AND (n.created_at, n.id) {$cmp} (:cc, :ci)";
        }

        $order = $desc
            ? "ORDER BY n.created_at DESC, n.id DESC LIMIT {$cap}"
            : "ORDER BY n.created_at ASC, n.id ASC LIMIT {$cap}";

        $from = $isAdmin
            ? 'FROM notifications n'
            : 'FROM notification_recipients nr JOIN notifications n ON n.id = nr.notification_id';

        $rows = DB::run(
            "SELECT n.*,
                    CASE WHEN r.notification_id IS NOT NULL AND r.read_at >= n.updated_at THEN 1 ELSE 0 END AS is_read,
                    CASE WHEN r.notification_id IS NOT NULL AND r.read_at <  n.updated_at THEN 1 ELSE 0 END AS is_edited,
                    CASE WHEN n.expires_at > 0 AND n.expires_at <= :now THEN 1 ELSE 0 END AS is_expired
             {$from}
             LEFT JOIN notification_reads r ON r.notification_id = n.id AND r.user_id = :uid
             WHERE 1=1{$scopeSql}{$searchSql}{$filterSql}
             {$order}",
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
     * Paginated history for a logged-in user (jump-to-page-N UI). A single indexed table
     * (notification_recipients JOIN notifications) with a plain LIMIT/OFFSET — offset
     * pagination still scans-and-discards at very deep pages (inherent to OFFSET, not
     * specific to this table), which is exactly why the keyset path above exists for
     * deep/adjacent navigation; this one stays for explicit "go to page N" jumps, which
     * are normally shallow.
     */
    public function historyForUser(int $userId, int $page, int $perPage, string $search = '', array $filters = [], bool $isAdmin = false): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset  = ($page - 1) * $perPage;
        $now     = time();

        $params    = [':now' => $now, ':uid' => $userId];
        $searchSql = $this->buildSearchClause($search, $params);
        $filterSql = $this->buildHistoryFilters($filters, $params);

        $scopeSql = '';
        if (!$isAdmin) {
            $params[':uid2'] = $userId;
            $scopeSql = ' AND nr.user_id = :uid2';
        }

        $from = $isAdmin
            ? 'FROM notifications n'
            : 'FROM notification_recipients nr JOIN notifications n ON n.id = nr.notification_id';

        return DB::run(
            "SELECT n.*,
                    CASE WHEN r.notification_id IS NOT NULL AND r.read_at >= n.updated_at THEN 1 ELSE 0 END AS is_read,
                    CASE WHEN r.notification_id IS NOT NULL AND r.read_at <  n.updated_at THEN 1 ELSE 0 END AS is_edited,
                    CASE WHEN n.expires_at > 0 AND n.expires_at <= :now THEN 1 ELSE 0 END AS is_expired
             {$from}
             LEFT JOIN notification_reads r ON r.notification_id = n.id AND r.user_id = :uid
             WHERE 1=1{$scopeSql}{$searchSql}{$filterSql}
             ORDER BY n.created_at DESC, n.id DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        )->fetchAll();
    }

    public function historyCountForUser(int $userId, string $search = '', array $filters = [], bool $isAdmin = false): int
    {
        // :uid is only referenced in the non-admin query's WHERE — a COUNT has no per-viewer
        // read-state join to need it for admins. With ATTR_EMULATE_PREPARES=false, binding a
        // named parameter that never appears in the SQL text is itself an "Invalid parameter
        // number" error (same rule that also forbids reusing one placeholder twice), so it
        // must only be added to $params when the query text actually uses it.
        $params    = $isAdmin ? [] : [':uid' => $userId];
        $searchSql = $this->buildSearchClause($search, $params, 'n');
        $filterSql = $this->buildHistoryFilters($filters, $params, 'n');

        if ($isAdmin) {
            return (int) DB::run(
                "SELECT COUNT(*) FROM notifications n WHERE 1=1{$searchSql}{$filterSql}",
                $params
            )->fetchColumn();
        }
        return (int) DB::run(
            "SELECT COUNT(*) FROM notification_recipients nr
             JOIN notifications n ON n.id = nr.notification_id
             WHERE nr.user_id = :uid{$searchSql}{$filterSql}",
            $params
        )->fetchColumn();
    }

    // ── Unread Tracking ─────────────────────────────────────

    /**
     * Count of unread notifications for a user — a single indexed join, no UNION.
     *
     * No expiry condition: expired notifications the user hasn't read yet
     * are still counted, so the badge stays until the user reads them,
     * even after expiry.
     */
    public function unreadCount(int $userId, bool $isAdmin = false): int
    {
        if ($isAdmin) {
            return (int) DB::run(
                "SELECT COUNT(*) FROM notifications n
                 LEFT JOIN notification_reads r ON r.notification_id = n.id AND r.user_id = :uid
                 WHERE r.notification_id IS NULL OR r.read_at < n.updated_at",
                [':uid' => $userId]
            )->fetchColumn();
        }
        return (int) DB::run(
            "SELECT COUNT(*) FROM notification_recipients nr
             JOIN notifications n ON n.id = nr.notification_id
             LEFT JOIN notification_reads r ON r.notification_id = n.id AND r.user_id = nr.user_id
             WHERE nr.user_id = :uid AND (r.notification_id IS NULL OR r.read_at < n.updated_at)",
            [':uid' => $userId]
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
        if ($isAdmin) {
            DB::run(
                "INSERT INTO notification_reads (user_id, notification_id)
                 SELECT :uid, n.id FROM notifications n
                 ON DUPLICATE KEY UPDATE read_at = CURRENT_TIMESTAMP",
                [':uid' => $userId]
            );
            return;
        }
        DB::run(
            "INSERT INTO notification_reads (user_id, notification_id)
             SELECT nr.user_id, nr.notification_id FROM notification_recipients nr WHERE nr.user_id = :uid
             ON DUPLICATE KEY UPDATE read_at = CURRENT_TIMESTAMP",
            [':uid' => $userId]
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

        $this->refreshRecipientsForNotification($id);

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
        $this->refreshRecipientsForNotification($id);

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