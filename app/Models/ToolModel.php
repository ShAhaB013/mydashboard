<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// ToolModel — CRUD operations on tools (MySQL)
// ═══════════════════════════════════════════════════════════

class ToolModel
{
    // ── Public queries ──────────────────────────────────────

    /** Tools reachable through the user's access role (directly, or via one of the role's categories) */
    public function allForUser(int $userId): array
    {
        return DB::run(
            'SELECT t.*, c.name AS badge
             FROM users u
             JOIN tools t
             LEFT JOIN categories c ON c.id = t.category_id
             LEFT JOIN role_tool_access rta ON rta.role_id = u.access_role_id AND rta.tool_id = t.id
             LEFT JOIN role_category_access rca ON rca.role_id = u.access_role_id AND rca.category_id = t.category_id
             WHERE u.id = :uid
               AND (rta.role_id IS NOT NULL OR rca.role_id IS NOT NULL)
             ORDER BY t.sort_order ASC',
            [':uid' => $userId]
        )->fetchAll();
    }

    /**
     * How many tools this user can reach, vs how many exist. Used to decide whether an
     * admin may reorder from the dashboard: reorderByIds() only accepts the complete id
     * set, so an admin whose own access has been restricted must not be offered the
     * control (the save would just fail).
     */
    public function countForUser(int $userId): int
    {
        return (int) DB::run(
            'SELECT COUNT(*)
             FROM users u
             JOIN tools t
             LEFT JOIN role_tool_access rta ON rta.role_id = u.access_role_id AND rta.tool_id = t.id
             LEFT JOIN role_category_access rca ON rca.role_id = u.access_role_id AND rca.category_id = t.category_id
             WHERE u.id = :uid
               AND (rta.role_id IS NOT NULL OR rca.role_id IS NOT NULL)',
            [':uid' => $userId]
        )->fetchColumn();
    }

    public function countAll(): int
    {
        return (int) DB::run('SELECT COUNT(*) FROM tools')->fetchColumn();
    }

    /** All tools (admin panel only) */
    public function all(): array
    {
        return DB::run(
            'SELECT t.*, c.name AS badge
             FROM tools t
             LEFT JOIN categories c ON c.id = t.category_id
             ORDER BY t.sort_order ASC'
        )->fetchAll();
    }

    /**
     * Server-side pagination for the admin panel + optional search.
     * Only the rows for the current page are fetched from the DB (independent of total count).
     */
    public function allForAdminPaginated(int $page, int $perPage, string $search = ''): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset  = ($page - 1) * $perPage;
        $like    = '%' . $search . '%';
        // LIMIT/OFFSET are validated integers, safe to interpolate directly
        $limitSql = sprintf('LIMIT %d OFFSET %d', $perPage, $offset);

        return DB::run(
            'SELECT t.*, c.name AS badge
             FROM tools t
             LEFT JOIN categories c ON c.id = t.category_id
             WHERE (:search = \'\'
                    OR t.title LIKE :like OR t.description LIKE :like2
                    OR t.path LIKE :like3 OR c.name LIKE :like4)
             ORDER BY t.sort_order ASC
             ' . $limitSql,
            [':search' => $search, ':like' => $like, ':like2' => $like, ':like3' => $like, ':like4' => $like]
        )->fetchAll();
    }

    public function countForAdmin(string $search = ''): int
    {
        $like = '%' . $search . '%';
        return (int) DB::run(
            'SELECT COUNT(*) FROM tools t
             LEFT JOIN categories c ON c.id = t.category_id
             WHERE (:search = \'\'
                    OR t.title LIKE :like OR t.description LIKE :like2
                    OR t.path LIKE :like3 OR c.name LIKE :like4)',
            [':search' => $search, ':like' => $like, ':like2' => $like, ':like3' => $like, ':like4' => $like]
        )->fetchColumn();
    }

    public function findById(int $id): ?array
    {
        $row = DB::run(
            'SELECT t.*, c.name AS badge
             FROM tools t
             LEFT JOIN categories c ON c.id = t.category_id
             WHERE t.id = :id',
            [':id' => $id]
        )->fetch();
        return $row ?: null;
    }

    /**
     * Find a tool by sequential index (compatibility with the legacy controller)
     * index = position in the sort_order list
     */
    public function find(int $index): ?array
    {
        $row = DB::run(
            'SELECT t.*, c.name AS badge
             FROM tools t
             LEFT JOIN categories c ON c.id = t.category_id
             ORDER BY t.sort_order ASC LIMIT 1 OFFSET :off',
            [':off' => $index]
        )->fetch();
        return $row ?: null;
    }

    // ── Write operations ────────────────────────────────────

    public function create(array $data): bool
    {
        $maxOrder = (int) DB::run(
            'SELECT COALESCE(MAX(sort_order), -1) FROM tools'
        )->fetchColumn();

        $categoryId = (new CategoryModel())->findOrCreateByName((string) ($data['badge'] ?? ''));

        DB::run(
            'INSERT INTO tools (title, description, path, category_id, icon_key, deco, accent_color, sort_order)
             VALUES (:title, :description, :path, :category_id, :icon_key, :deco, :accent_color, :sort_order)',
            [
                ':title'        => $data['title']       ?? '',
                ':description'  => $data['description'] ?? '',
                ':path'         => $data['path']        ?? '',
                ':category_id'  => $categoryId,
                ':icon_key'     => $data['iconKey']     ?? 'star',
                ':deco'         => $data['deco']        ?? 'generic',
                ':accent_color' => $data['accentColor'] ?? '',
                ':sort_order'   => $maxOrder + 1,
            ]
        );
        return true;
    }

    /** Edit a tool by sequential index */
    public function update(int $index, array $data): bool
    {
        $tool = $this->find($index);
        if (!$tool) return false;

        $categoryId = (new CategoryModel())->findOrCreateByName((string) ($data['badge'] ?? ''));

        DB::run(
            'UPDATE tools SET
                title        = :title,
                description  = :description,
                path         = :path,
                category_id  = :category_id,
                icon_key     = :icon_key,
                deco         = :deco,
                accent_color = :accent_color
             WHERE id = :id',
            [
                ':title'        => $data['title']       ?? '',
                ':description'  => $data['description'] ?? '',
                ':path'         => $data['path']        ?? '',
                ':category_id'  => $categoryId,
                ':icon_key'     => $data['iconKey']     ?? 'star',
                ':deco'         => $data['deco']        ?? 'generic',
                ':accent_color' => $data['accentColor'] ?? '',
                ':id'           => $tool['id'],
            ]
        );

        if ((int) $tool['category_id'] !== (int) $categoryId) {
            $this->refreshRecipients($this->usersWithToolGrant((int) $tool['id']));
        }

        return true;
    }

    /** Edit a tool directly by ID */
    public function updateById(int $id, array $data): bool
    {
        $categoryId    = (new CategoryModel())->findOrCreateByName((string) ($data['badge'] ?? ''));
        $oldCategoryId = DB::run('SELECT category_id FROM tools WHERE id = :id', [':id' => $id])->fetchColumn();

        DB::run(
            'UPDATE tools SET
                title        = :title,
                description  = :description,
                path         = :path,
                category_id  = :category_id,
                icon_key     = :icon_key,
                deco         = :deco,
                accent_color = :accent_color
             WHERE id = :id',
            [
                ':title'        => $data['title']       ?? '',
                ':description'  => $data['description'] ?? '',
                ':path'         => $data['path']        ?? '',
                ':category_id'  => $categoryId,
                ':icon_key'     => $data['iconKey']     ?? 'star',
                ':deco'         => $data['deco']        ?? 'generic',
                ':accent_color' => $data['accentColor'] ?? '',
                ':id'           => $id,
            ]
        );

        if ((int) $oldCategoryId !== (int) $categoryId) {
            $this->refreshRecipients($this->usersWithToolGrant($id));
        }

        return true;
    }

    /** Delete a tool by sequential index */
    public function delete(int $index): bool
    {
        $tool = $this->find($index);
        if (!$tool) return false;

        return $this->deleteById((int) $tool['id']);
    }

    /** Delete a tool directly by ID */
    public function deleteById(int $id): bool
    {
        // Capture affected users BEFORE role_tool_access cascades away, recompute AFTER the
        // tool is gone (recomputing first would still count the deleted tool's category path)
        $userIds = $this->usersWithToolGrant($id);
        DB::run('DELETE FROM tools WHERE id = :id', [':id' => $id]);
        $this->refreshRecipients($userIds);
        return true;
    }

    /**
     * Users whose access role grants this tool directly (role_tool_access). A tool's
     * category decides which category-badged notifications those users reach through
     * NotificationModel's tool path, so they need a recompute when the tool is
     * re-categorized or deleted. (Category-level grants don't depend on the tool.)
     */
    private function usersWithToolGrant(int $toolId): array
    {
        return array_map('intval', array_column(DB::run(
            'SELECT u.id FROM users u
             JOIN role_tool_access rta ON rta.role_id = u.access_role_id
             WHERE rta.tool_id = :id',
            [':id' => $toolId]
        )->fetchAll(), 'id'));
    }

    /** Full notification-visibility recompute for each given user */
    private function refreshRecipients(array $userIds): void
    {
        $nm = new NotificationModel();
        foreach ($userIds as $uid) {
            $nm->refreshRecipientsForUser((int) $uid);
        }
    }

    /** Reorder based on an array of indexes */
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
     * Global reorder based on the full array of ids.
     * For the "reorder all cards" mode — independent of pagination.
     * Only applies when the set of ids exactly matches all tools
     * (prevents corrupting the order with an incomplete list).
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

    /**
     * saveAll — compatibility with DecoModel (replacing badge on dependent tools)
     * $tools must be an array of DB records (with id)
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
     * Lightweight version for panel-wide tasks that need "all tools"
     * but not the heavy fields (description/path/accent):
     *   - reorder mode (drag-drop)
     *   - two-tier access modal
     *   - "used in" count for icon/deco
     * Used instead of shipping the full dataset on every load.
     */
    public static function toLite(array $rows): array
    {
        return array_map(fn($t) => [
            'id'        => (int) $t['id'],
            'title'     => $t['title'],
            'badge'     => $t['badge']    ?? '',
            'iconKey'   => $t['icon_key'] ?? 'star',
            'deco'      => $t['deco']     ?? 'generic',
        ], $rows);
    }

    // ── Helper: convert DB output to the legacy JSON format ───────────
    // (for compatibility with script.js, which expects iconKey)
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
            'id'           => (int) $t['id'],
        ], $rows);
    }
}