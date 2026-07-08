<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// NotificationModel — تمام عملیات دیتابیس برای اعلان‌ها
// ═══════════════════════════════════════════════════════════

class NotificationModel
{
    // حداقل طول عبارت جستجو برای استفاده از ایندکس FULLTEXT (باید با
    // innodb_ft_min_token_size روی سرور MySQL هماهنگ باشد؛ عبارات کوتاه‌تر
    // به LIKE سقوط می‌کنند چون FULLTEXT اصلاً آن‌ها را توکنایز نکرده است)
    private const FTS_MIN_TOKEN = 3;

    // سقف فید محدود زنگوله (bell) — رکوردهای قدیمی‌تر از صفحه‌ی تاریخچه در دسترس می‌مانند
    private const BELL_CAP = 100;

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
             ORDER BY n.created_at DESC
             LIMIT ' . self::BELL_CAP,
            [':now' => $now]
        )->fetchAll();
    }

    /**
     * زیرکوئری UNION سه‌شاخه‌ی «اعلان‌های قابل‌دسترسِ» یک کاربر
     * (public ∪ target_all_users ∪ badge-matched) — به‌جای یک JOIN سه‌جدولی
     * + شرط OR که هیچ ترکیبی از ایندکس نمی‌تواند بهینه‌اش کند (index merge
     * فقط برای OR روی یک جدول کار می‌کند)، هر شاخه با ایندکس اختصاصی خودش
     * (idx_pub_created / idx_target_created / badge join) اسکن می‌شود.
     * UNION (نه UNION ALL) خودش ردیف‌های تکراری بین شاخه‌ها را حذف می‌کند
     * چون ستون‌های انتخابی برای یک ردیف مشترک عینا یکسان‌اند.
     *
     * @param string $cols ستون‌های انتخابی (n.* یا فقط زیرمجموعه‌ی لازم)
     * @param string $uidParam نام پارامتر PDO برای user_id (باید بین فراخوان‌ها یکتا باشد)
     * @param int|null $limitPerBranch اگر ست شود، هر شاخه جداگانه LIMIT می‌خورد (فید محدود زنگوله)
     */
    private function accessibleUnionSql(string $cols, string $uidParam, ?int $limitPerBranch = null): string
    {
        $tail = $limitPerBranch !== null
            ? ' ORDER BY n.created_at DESC, n.id DESC LIMIT ' . $limitPerBranch
            : '';
        return "(SELECT {$cols} FROM notifications n WHERE n.is_public = 1{$tail})
                 UNION
                 (SELECT {$cols} FROM notifications n WHERE n.target_all_users = 1{$tail})
                 UNION
                 (SELECT {$cols} FROM notifications n
                    JOIN notification_badges nb ON nb.notification_id = n.id
                    JOIN category_access     ca ON ca.badge = nb.badge AND ca.user_id = :{$uidParam}
                  {$tail})";
    }

    /**
     * اعلان‌های قابل نمایش برای کاربر لاگین‌کرده (فید محدود زنگوله)
     * شامل: عمومی + همه کاربران + badge مطابق دسترسی کاربر
     *
     * برای مقیاس بزرگ به آخرین BELL_CAP مورد محدود می‌شود (هر شاخه جداگانه
     * تا BELL_CAP کاندید می‌دهد، سپس با اولویت ناخوانده‌ها مرتب و دوباره
     * به BELL_CAP برش می‌خورد) — دسترسی به رکوردهای قدیمی‌تر از این پنجره
     * از صفحه‌ی تاریخچه (/notifications، historyForUser) تامین می‌شود، نه از اینجا.
     */
    public function allActiveForUser(int $userId): array
    {
        $now   = time();
        $union = $this->accessibleUnionSql('n.*', 'uid1', self::BELL_CAP);
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
            [':uid1' => $userId, ':uid2' => $userId, ':now' => $now, ':now2' => $now]
        )->fetchAll();
    }

    /**
     * نسخه‌ی سبک (فقط id/created_at) فید مهمان — برای محاسبه‌ی ارزان‌قیمتِ ETag
     * پیش از اجرای کوئری کامل + serialize (که فقط وقتی چیزی واقعا عوض شده لازم است).
     */
    public function guestFingerprint(): array
    {
        return DB::run(
            'SELECT id, created_at FROM notifications WHERE is_public = 1 ORDER BY created_at DESC LIMIT ' . self::BELL_CAP
        )->fetchAll();
    }

    /**
     * نسخه‌ی سبک (id/updated_at/is_read) فید کاربر — همان منطق انتخاب/کپ/مرتب‌سازی
     * allActiveForUser را با کمترین ستون‌ها تکرار می‌کند تا فینگرپرینت واقعا هر تغییری
     * (اعلان جدید/ویرایش‌شده، خروج از پنجره‌ی BELL_CAP، یا تغییر read-state خودِ کاربر)
     * را ببیند — بر خلاف یک امضای تقریبی مثل MAX(updated_at)+COUNT(*) که read-state را نمی‌بیند.
     */
    public function activeUserFingerprint(int $userId): array
    {
        $now   = time();
        $union = $this->accessibleUnionSql('n.id, n.created_at, n.updated_at, n.expires_at', 'uid1', self::BELL_CAP);
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
            [':uid1' => $userId, ':uid2' => $userId, ':now' => $now]
        )->fetchAll();
    }

    /**
     * ساخت شرط جستجوی متنی — فقط وقتی $search خالی نباشد به SQL اضافه می‌شود
     * (هم‌سو با buildHistoryFilters که فیلترهای خالی را اصلاً به کوئری اضافه
     * نمی‌کند، تا مسیر پرتکرارِ «مرور بدون جستجو» درگیر اسکن جدول نشود).
     *
     * برای عبارت‌های به‌اندازه‌کافی بلند از ایندکس FULLTEXT (BOOLEAN MODE،
     * تطبیق پیشوند کلمه) استفاده می‌شود؛ برای عبارت‌های کوتاه‌تر از حد توکن
     * سرور، MATCH...AGAINST چیزی برنمی‌گرداند، پس به LIKE قبلی سقوط می‌کنیم.
     * توجه: این یعنی معنای جستجو برای عبارت‌های بلند از «substring دلخواه»
     * به «تطبیق کلمه/پیشوند کلمه» تغییر می‌کند.
     */
    /**
     * $suffix: چون با ATTR_EMULATE_PREPARES=false نمی‌توان یک نام پارامتر را در یک
     * کوئری تکرار کرد، فراخوان‌هایی که این شرط را چندبار در یک کوئری (مثلا سه شاخه‌ی
     * UNION) به کار می‌برند باید suffix متفاوت بدهند تا نام‌های :ftq/:like و... یکتا بمانند.
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
     * تبدیل عبارت جستجوی کاربر به کوئری امنِ BOOLEAN MODE — عملگرهای
     * ویژه‌ی این حالت (+ - * " ( ) ~ < >) حذف می‌شوند تا کاربر نتواند
     * عملگر غیرمنتظره تزریق کند، و هر کلمه با * پسوندی می‌شود (تطبیق پیشوند)
     * تا تجربه‌ی جستجوی زنده به رفتار قبلی LIKE نزدیک بماند.
     */
    private function buildBooleanQuery(string $search): string
    {
        $clean = preg_replace('/[+\-*"()~<>]+/u', ' ', $search);
        $words = array_filter(preg_split('/\s+/u', trim($clean)), static fn($w) => $w !== '');
        $terms = array_map(static fn($w) => $w . '*', $words);
        return implode(' ', $terms);
    }

    /**
     * ساخت شرط‌های جستجوی پیشرفته (تاریخ ایجاد + وضعیت انقضا).
     * پارامترها به آرایه $params اضافه می‌شوند و رشته SQL برگردانده می‌شود.
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
     * تاریخچه‌ی مهمان با پیمایش keyset (فلش Prev/Next مجاور — سریع در هر عمقی،
     * بر خلاف historyForGuest که OFFSET سنتی دارد و برای «رفتن به صفحه‌ی N» است).
     * @param array{created_at:string,id:int}|null $cursor
     * @param string $dir 'next' (قدیمی‌تر) یا 'prev' (جدیدتر)
     * @return array ممکن است تا perPage+1 ردیف داشته باشد (ردیف اضافه = نشانه‌ی «صفحه‌ی بعد/قبل هست»؛ caller آن را جدا می‌کند)
     */
    public function historyForGuestKeyset(?array $cursor, string $dir, int $perPage, string $search = '', array $filters = []): array
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
                    CASE WHEN n.expires_at > 0 AND n.expires_at <= :now THEN 1 ELSE 0 END AS is_expired
             FROM notifications n
             WHERE n.is_public = 1{$cursorSql}{$searchSql}{$filterSql}
             {$order}
             LIMIT {$cap}",
            $params
        )->fetchAll();

        return $desc ? $rows : array_reverse($rows);
    }

    /**
     * تاریخچه‌ی کاربر با پیمایش keyset — همان استدلال کپ-هر-شاخه‌ی historyForUser
     * (ردیف در جایگاه p از یک شاخه‌ی مرتب‌شده فقط اگر p<=cap می‌تواند در نتیجه‌ی
     * سراسری باشد) با اضافه‌شدن شرط cursor به هر شاخه.
     */
    public function historyForUserKeyset(int $userId, ?array $cursor, string $dir, int $perPage, string $search = '', array $filters = []): array
    {
        $perPage = max(1, min(100, $perPage));
        $cap     = $perPage + 1;
        $now     = time();
        $desc    = $dir !== 'prev';

        $params = [':uid1' => $userId, ':uid2' => $userId, ':now' => $now];
        $s1 = $this->buildSearchClause($search, $params, 'n', '_b1');
        $f1 = $this->buildHistoryFilters($filters, $params, 'n', '_b1');
        $s2 = $this->buildSearchClause($search, $params, 'n', '_b2');
        $f2 = $this->buildHistoryFilters($filters, $params, 'n', '_b2');
        $s3 = $this->buildSearchClause($search, $params, 'n', '_b3');
        $f3 = $this->buildHistoryFilters($filters, $params, 'n', '_b3');

        $c1 = $c2 = $c3 = '';
        if ($cursor !== null) {
            $cmp = $desc ? '<' : '>';
            $params[':cc1'] = $cursor['created_at']; $params[':ci1'] = $cursor['id'];
            $params[':cc2'] = $cursor['created_at']; $params[':ci2'] = $cursor['id'];
            $params[':cc3'] = $cursor['created_at']; $params[':ci3'] = $cursor['id'];
            $c1 = " AND (n.created_at, n.id) {$cmp} (:cc1, :ci1)";
            $c2 = " AND (n.created_at, n.id) {$cmp} (:cc2, :ci2)";
            $c3 = " AND (n.created_at, n.id) {$cmp} (:cc3, :ci3)";
        }

        $order = $desc
            ? "ORDER BY n.created_at DESC, n.id DESC LIMIT {$cap}"
            : "ORDER BY n.created_at ASC, n.id ASC LIMIT {$cap}";

        $union = "(SELECT n.* FROM notifications n WHERE n.is_public = 1{$c1}{$s1}{$f1} {$order})
                   UNION
                   (SELECT n.* FROM notifications n WHERE n.target_all_users = 1{$c2}{$s2}{$f2} {$order})
                   UNION
                   (SELECT n.* FROM notifications n
                      JOIN notification_badges nb ON nb.notification_id = n.id
                      JOIN category_access     ca ON ca.badge = nb.badge AND ca.user_id = :uid1
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
     * لیست ادمین با پیمایش keyset (معادل allForAdminPaginated برای فلش Prev/Next مجاور)
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
                    CASE WHEN n.expires_at > 0 AND n.expires_at <= :now THEN 1 ELSE 0 END AS is_expired
             FROM notifications n
             WHERE 1=1{$cursorSql}{$searchSql}{$filterSql}
             {$order}
             LIMIT {$cap}",
            $params
        )->fetchAll();

        return $desc ? $rows : array_reverse($rows);
    }

    /**
     * تاریخچه اعلان‌های عمومی برای مهمان — با صفحه‌بندی و جستجو
     * شامل اعلان‌های منقضی‌شده هم می‌شود (تاریخچه کامل)
     */
    public function historyForGuest(int $page, int $perPage, string $search = '', array $filters = []): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset  = ($page - 1) * $perPage;
        $now     = time();

        $params    = [':now' => $now];
        $searchSql = $this->buildSearchClause($search, $params);
        $filterSql = $this->buildHistoryFilters($filters, $params);

        // LIMIT/OFFSET به‌صورت عدد صحیح اعتبارسنجی‌شده مستقیم در کوئری تزریق می‌شوند
        $limitSql = sprintf('LIMIT %d OFFSET %d', $perPage, $offset);

        return DB::run(
            'SELECT n.*,
                    CASE WHEN n.expires_at > 0 AND n.expires_at <= :now THEN 1 ELSE 0 END AS is_expired
             FROM notifications n
             WHERE n.is_public = 1
             ' . $searchSql . $filterSql . '
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
        $params    = [];
        $searchSql = $this->buildSearchClause($search, $params);
        $filterSql = $this->buildHistoryFilters($filters, $params);

        return (int) DB::run(
            'SELECT COUNT(*)
             FROM notifications n
             WHERE n.is_public = 1
             ' . $searchSql . $filterSql,
            $params
        )->fetchColumn();
    }

    /**
     * تاریخچه اعلان‌ها برای کاربر (شامل منقضی‌شده‌ها) با صفحه‌بندی
     * جهت صفحه notifications.php
     */
    /**
     * تاریخچه صفحه‌بندی‌شده برای کاربر لاگین‌کرده.
     *
     * برخلاف allActiveForUser (که فید محدود زنگوله است)، این متد باید بتواند به
     * هر عمقی از تاریخچه برسد؛ پس UNION را بدون کپ کلی نمی‌سازیم. اما محاسبه‌ی
     * «۱۰ ردیف صفحه‌ی اول» با ساخت کل مجموعه‌ی قابل‌دسترس (که می‌تواند ده‌ها هزار
     * ردیف باشد) و بعد LIMIT زدن، در مقیاس بزرگ به‌شدت کند است (اندازه‌گیری شد:
     * ~1.7 ثانیه روی ۱۰۰هزار ردیف). راه‌حل: هر شاخه‌ی UNION را با فیلتر/جستجوی
     * خودش، ORDER BY created_at DESC و LIMIT (offset+perPage) کپ می‌کنیم — چون
     * یک ردیف در جایگاه p از یک لیستِ مرتب‌شده‌ی نزولی نمی‌تواند در top-(offset+perPage)
     * سراسری (اجتماع چند لیست مرتب) باشد مگر p <= offset+perPage. این کپ فقط تا
     * عمقی که واقعا لازم است (OFFSET صفحه‌ی درخواستی) بزرگ می‌شود؛ برای پیمایش
     * عمیق (شماره صفحه‌ی خیلی بزرگ) مسیر کرسر/keyset (فاز ۳) استفاده می‌شود که
     * اصلا به این کپ وابسته نیست.
     */
    public function historyForUser(int $userId, int $page, int $perPage, string $search = '', array $filters = []): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset  = ($page - 1) * $perPage;
        $now     = time();
        $cap     = $offset + $perPage;

        $params = [':uid1' => $userId, ':uid2' => $userId, ':now' => $now];
        $s1 = $this->buildSearchClause($search, $params, 'n', '_b1');
        $f1 = $this->buildHistoryFilters($filters, $params, 'n', '_b1');
        $s2 = $this->buildSearchClause($search, $params, 'n', '_b2');
        $f2 = $this->buildHistoryFilters($filters, $params, 'n', '_b2');
        $s3 = $this->buildSearchClause($search, $params, 'n', '_b3');
        $f3 = $this->buildHistoryFilters($filters, $params, 'n', '_b3');
        $order = "ORDER BY n.created_at DESC, n.id DESC LIMIT {$cap}";

        $union = "(SELECT n.* FROM notifications n WHERE n.is_public = 1{$s1}{$f1} {$order})
                   UNION
                   (SELECT n.* FROM notifications n WHERE n.target_all_users = 1{$s2}{$f2} {$order})
                   UNION
                   (SELECT n.* FROM notifications n
                      JOIN notification_badges nb ON nb.notification_id = n.id
                      JOIN category_access     ca ON ca.badge = nb.badge AND ca.user_id = :uid1
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

    /**
     * تعداد کل برای صفحه‌بندی تاریخچه
     */
    public function historyCountForUser(int $userId, string $search = '', array $filters = []): int
    {
        // فقط ستون‌های لازم برای شمارش/فیلتر (نه n.* کامل) — COUNT سبک‌تر
        $union  = $this->accessibleUnionSql('n.id, n.title, n.body, n.created_at, n.expires_at', 'uid1');
        $params = [':uid1' => $userId];
        $searchSql = $this->buildSearchClause($search, $params, 'u');
        $filterSql = $this->buildHistoryFilters($filters, $params, 'u');

        return (int) DB::run(
            "SELECT COUNT(*) FROM ({$union}) u WHERE 1=1{$searchSql}{$filterSql}",
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
        $union = $this->accessibleUnionSql('n.id, n.updated_at', 'uid1');
        return (int) DB::run(
            "SELECT COUNT(*) FROM (
                SELECT u.id FROM ({$union}) u
                LEFT JOIN notification_reads r ON r.notification_id = u.id AND r.user_id = :uid2
                WHERE r.notification_id IS NULL OR r.read_at < u.updated_at
             ) t",
            [':uid1' => $userId, ':uid2' => $userId]
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
        $union = $this->accessibleUnionSql('n.id', 'uid2');
        DB::run(
            "INSERT INTO notification_reads (user_id, notification_id)
             SELECT :uid1, u.id FROM ({$union}) u
             ON DUPLICATE KEY UPDATE read_at = CURRENT_TIMESTAMP",
            [':uid1' => $userId, ':uid2' => $userId]
        );
    }

    // ── Admin Queries ───────────────────────────────────────

    /**
     * اعلان‌های پنل ادمین با صفحه‌بندی واقعی سمت سرور و جستجوی اختیاری
     */
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
                    CASE WHEN n.expires_at > 0 AND n.expires_at <= :now THEN 1 ELSE 0 END AS is_expired
             FROM notifications n
             WHERE 1=1
             ' . $searchSql . $filterSql . '
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