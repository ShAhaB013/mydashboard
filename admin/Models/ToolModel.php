<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// ToolModel — عملیات CRUD روی ابزارها (MySQL)
// ═══════════════════════════════════════════════════════════

class ToolModel
{
    // ── Public queries ──────────────────────────────────────

    /** همه ابزارهای عمومی (بدون لاگین) */
    public function allPublic(): array
    {
        return DB::run(
            'SELECT * FROM tools WHERE is_public = 1 ORDER BY sort_order ASC'
        )->fetchAll();
    }

    /** ابزارهای قابل نمایش برای یک کاربر مشخص */
    public function allForUser(int $userId): array
    {
        return DB::run(
            'SELECT DISTINCT t.*
             FROM tools t
             WHERE
                 t.is_public = 1
                 OR t.id IN (
                     SELECT tool_id FROM tool_access WHERE user_id = :uid
                 )
                 OR (
                     t.badge != \'\'
                     AND t.badge IN (
                         SELECT badge FROM category_access WHERE user_id = :uid2
                     )
                 )
             ORDER BY t.sort_order ASC',
            [':uid' => $userId, ':uid2' => $userId]
        )->fetchAll();
    }

    /** همه ابزارها (فقط برای پنل ادمین) */
    public function all(): array
    {
        return DB::run(
            'SELECT * FROM tools ORDER BY sort_order ASC'
        )->fetchAll();
    }

    /**
     * صفحه‌بندی سمت سرور برای پنل ادمین + جستجوی اختیاری.
     * فقط ردیف‌های صفحهٔ جاری از DB می‌آیند (مستقل از کل تعداد).
     */
    public function allForAdminPaginated(int $page, int $perPage, string $search = ''): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset  = ($page - 1) * $perPage;
        $like    = '%' . $search . '%';
        // LIMIT/OFFSET اعتبارسنجی‌شده مستقیم تزریق می‌شوند (عدد صحیح)
        $limitSql = sprintf('LIMIT %d OFFSET %d', $perPage, $offset);

        return DB::run(
            'SELECT * FROM tools
             WHERE (:search = \'\'
                    OR title LIKE :like OR description LIKE :like2
                    OR path LIKE :like3 OR badge LIKE :like4)
             ORDER BY sort_order ASC
             ' . $limitSql,
            [':search' => $search, ':like' => $like, ':like2' => $like, ':like3' => $like, ':like4' => $like]
        )->fetchAll();
    }

    /** تعداد کل ابزارها برای صفحه‌بندی ادمین (با جستجوی اختیاری) */
    public function countForAdmin(string $search = ''): int
    {
        $like = '%' . $search . '%';
        return (int) DB::run(
            'SELECT COUNT(*) FROM tools
             WHERE (:search = \'\'
                    OR title LIKE :like OR description LIKE :like2
                    OR path LIKE :like3 OR badge LIKE :like4)',
            [':search' => $search, ':like' => $like, ':like2' => $like, ':like3' => $like, ':like4' => $like]
        )->fetchColumn();
    }

    /** یافتن ابزار با ID */
    public function findById(int $id): ?array
    {
        $row = DB::run(
            'SELECT * FROM tools WHERE id = :id',
            [':id' => $id]
        )->fetch();
        return $row ?: null;
    }

    /**
     * یافتن ابزار با index ترتیبی (سازگاری با کنترلر قدیمی)
     * index = موقعیت در لیست sort_order
     */
    public function find(int $index): ?array
    {
        $row = DB::run(
            'SELECT * FROM tools ORDER BY sort_order ASC LIMIT 1 OFFSET :off',
            [':off' => $index]
        )->fetch();
        return $row ?: null;
    }

    // ── Write operations ────────────────────────────────────

    /** افزودن ابزار جدید */
    public function create(array $data): bool
    {
        $maxOrder = (int) DB::run(
            'SELECT COALESCE(MAX(sort_order), -1) FROM tools'
        )->fetchColumn();

        DB::run(
            'INSERT INTO tools (title, description, path, badge, icon_key, deco, accent_color, is_public, sort_order)
             VALUES (:title, :description, :path, :badge, :icon_key, :deco, :accent_color, :is_public, :sort_order)',
            [
                ':title'        => $data['title']       ?? '',
                ':description'  => $data['description'] ?? '',
                ':path'         => $data['path']        ?? '',
                ':badge'        => $data['badge']       ?? '',
                ':icon_key'     => $data['iconKey']     ?? 'star',
                ':deco'         => $data['deco']        ?? 'generic',
                ':accent_color' => $data['accentColor'] ?? '',
                ':is_public'    => (int) ($data['is_public'] ?? 0),
                ':sort_order'   => $maxOrder + 1,
            ]
        );
        return true;
    }

    /** ویرایش ابزار با index ترتیبی */
    public function update(int $index, array $data): bool
    {
        $tool = $this->find($index);
        if (!$tool) return false;

        DB::run(
            'UPDATE tools SET
                title        = :title,
                description  = :description,
                path         = :path,
                badge        = :badge,
                icon_key     = :icon_key,
                deco         = :deco,
                accent_color = :accent_color
             WHERE id = :id',
            [
                ':title'        => $data['title']       ?? '',
                ':description'  => $data['description'] ?? '',
                ':path'         => $data['path']        ?? '',
                ':badge'        => $data['badge']       ?? '',
                ':icon_key'     => $data['iconKey']     ?? 'star',
                ':deco'         => $data['deco']        ?? 'generic',
                ':accent_color' => $data['accentColor'] ?? '',
                ':id'           => $tool['id'],
            ]
        );
        return true;
    }

    /** ویرایش ابزار با ID مستقیم */
    public function updateById(int $id, array $data): bool
    {
        DB::run(
            'UPDATE tools SET
                title        = :title,
                description  = :description,
                path         = :path,
                badge        = :badge,
                icon_key     = :icon_key,
                deco         = :deco,
                accent_color = :accent_color
             WHERE id = :id',
            [
                ':title'        => $data['title']       ?? '',
                ':description'  => $data['description'] ?? '',
                ':path'         => $data['path']        ?? '',
                ':badge'        => $data['badge']       ?? '',
                ':icon_key'     => $data['iconKey']     ?? 'star',
                ':deco'         => $data['deco']        ?? 'generic',
                ':accent_color' => $data['accentColor'] ?? '',
                ':id'           => $id,
            ]
        );
        return true;
    }

    /** حذف ابزار با index ترتیبی */
    public function delete(int $index): bool
    {
        $tool = $this->find($index);
        if (!$tool) return false;

        DB::run('DELETE FROM tools WHERE id = :id', [':id' => $tool['id']]);
        return true;
    }

    /** تغییر وضعیت is_public */
    public function togglePublic(int $id): bool
    {
        DB::run(
            'UPDATE tools SET is_public = 1 - is_public WHERE id = :id',
            [':id' => $id]
        );
        return true;
    }

    /** مرتب‌سازی مجدد بر اساس آرایه‌ای از index ها */
    public function reorder(array $order): bool
    {
        $all = $this->all();
        if (count($order) !== count($all)) return false;

        $pdo  = DB::get();
        $stmt = $pdo->prepare(
            'UPDATE tools SET sort_order = :ord WHERE id = :id'
        );

        foreach ($order as $newPos => $oldIndex) {
            $tool = $all[(int) $oldIndex] ?? null;
            if (!$tool) return false;
            $stmt->execute([':ord' => $newPos, ':id' => $tool['id']]);
        }
        return true;
    }


    /**
     * مرتب‌سازی سراسری بر اساس آرایه کامل id ها.
     * برای حالت «مرتب‌سازی همه کارت‌ها» — مستقل از صفحه‌بندی.
     * فقط وقتی اعمال می‌شود که مجموعه id ها دقیقا برابر کل ابزارها باشد
     * (جلوگیری از خراب‌شدن ترتیب با لیست ناقص).
     */
    public function reorderByIds(array $ids): bool
    {
        $allIds = array_map('intval', array_column($this->all(), 'id'));
        $ids    = array_map('intval', $ids);

        if (count($ids) !== count($allIds)) return false;
        if (array_diff($allIds, $ids) || array_diff($ids, $allIds)) return false;

        $stmt = DB::get()->prepare('UPDATE tools SET sort_order = :o WHERE id = :id');
        foreach ($ids as $pos => $id) {
            $stmt->execute([':o' => $pos, ':id' => $id]);
        }
        return true;
    }

    /** حذف ابزار با id مستقیم */
    public function deleteById(int $id): bool
    {
        DB::run('DELETE FROM tools WHERE id = :id', [':id' => $id]);
        return true;
    }

    /**
     * saveAll — سازگاری با DecoModel (جایگزینی badge در ابزارهای وابسته)
     * $tools باید آرایه‌ای از رکوردهای DB باشد (دارای id)
     */
    public function saveAll(array $tools): bool
    {
        $stmt = DB::get()->prepare(
            'UPDATE tools SET deco = :deco WHERE id = :id'
        );
        foreach ($tools as $t) {
            if (isset($t['id'])) {
                $stmt->execute([':deco' => $t['deco'] ?? 'generic', ':id' => $t['id']]);
            }
        }
        return true;
    }

    /**
     * نسخهٔ سبک برای کارهای سراسریِ پنل که به «همهٔ ابزارها» نیاز دارند
     * ولی نه به فیلدهای سنگین (description/path/accent):
     *   - حالت مرتب‌سازی (drag-drop)
     *   - مودال دسترسی دو سطحی
     *   - شمارش «استفاده‌شده در» آیکون/دکو
     * این آرایه به‌جای تزریقِ کلِ دیتاستِ کامل در هر بار لود استفاده می‌شود.
     */
    public static function toLite(array $rows): array
    {
        return array_map(fn($t) => [
            'id'        => (int) $t['id'],
            'title'     => $t['title'],
            'badge'     => $t['badge']    ?? '',
            'iconKey'   => $t['icon_key'] ?? 'star',
            'deco'      => $t['deco']     ?? 'generic',
            'is_public' => (bool) ($t['is_public'] ?? false),
        ], $rows);
    }

    // ── Helper: تبدیل خروجی DB به فرمت JSON قدیمی ───────────
    // (برای سازگاری با script.js که انتظار iconKey دارد)
    public static function toFrontend(array $rows): array
    {
        return array_map(fn($t) => [
            'title'        => $t['title'],
            'description'  => $t['description'] ?? '',
            'path'         => $t['path'],
            'badge'        => $t['badge']        ?? '',
            'iconKey'      => $t['icon_key']     ?? 'star',
            'deco'         => $t['deco']         ?? 'generic',
            'accentColor'  => $t['accent_color'] ?? '',
            'is_public'    => (bool) ($t['is_public'] ?? false),
            'id'           => (int) $t['id'],
        ], $rows);
    }
}