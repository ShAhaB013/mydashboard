<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// CategoryModel — single source of truth for tool/notification/access
// categories (backed by the `categories` table; category_id everywhere else)
// ═══════════════════════════════════════════════════════════

class CategoryModel
{
    /**
     * Category names currently carried by at least one tool — the whitelist used
     * everywhere a badge/category selection is validated (notification targeting,
     * user category access) and everywhere the admin UI lists "available" categories.
     */
    public function namesInUseByTools(): array
    {
        return array_column(
            DB::run(
                'SELECT DISTINCT c.name
                 FROM categories c
                 JOIN tools t ON t.category_id = c.id
                 ORDER BY c.name ASC'
            )->fetchAll(),
            'name'
        );
    }

    /** Resolve a category name to its id — only if it's currently tool-linked (whitelist) */
    public function findIdByName(string $name): ?int
    {
        $name = trim($name);
        if ($name === '') return null;

        $id = DB::run(
            'SELECT c.id
             FROM categories c
             JOIN tools t ON t.category_id = c.id
             WHERE c.name = :name
             LIMIT 1',
            [':name' => $name]
        )->fetchColumn();

        return $id !== false ? (int) $id : null;
    }

    /**
     * Resolve a category name to its id, creating the category if it doesn't exist yet.
     * The only place new categories are created — via the tool editor.
     */
    public function findOrCreateByName(string $name): ?int
    {
        $name = trim($name);
        if ($name === '') return null;

        DB::run('INSERT IGNORE INTO categories (name) VALUES (:name)', [':name' => $name]);

        $id = DB::run('SELECT id FROM categories WHERE name = :name', [':name' => $name])->fetchColumn();
        return $id !== false ? (int) $id : null;
    }

    /**
     * Every category (including ones with zero tools — "orphans" left behind when a
     * category's last tool is deleted/recategorized, since there is no cascading
     * cleanup for that) with counts of what still references it, for the category
     * management page. tool_count=0 rows are the ones a category_access/notification_badges
     * grant could be silently pointing at with no tool left to make it discoverable
     * anywhere else in the admin UI.
     */
    public function allWithCounts(): array
    {
        return DB::run(
            'SELECT c.id, c.name,
                    COUNT(DISTINCT t.id)   AS tool_count,
                    COUNT(DISTINCT ca.user_id)          AS access_count,
                    COUNT(DISTINCT nb.notification_id)  AS notification_count
             FROM categories c
             LEFT JOIN tools t                ON t.category_id = c.id
             LEFT JOIN category_access ca      ON ca.category_id = c.id
             LEFT JOIN notification_badges nb  ON nb.category_id = c.id
             GROUP BY c.id, c.name
             ORDER BY c.name ASC'
        )->fetchAll();
    }

    /** Rename a category. Fails if the new name is empty or already used by another category. */
    public function rename(int $id, string $newName): bool
    {
        $newName = trim($newName);
        if ($newName === '') return false;

        $clash = DB::run(
            'SELECT id FROM categories WHERE name = :name AND id != :id',
            [':name' => $newName, ':id' => $id]
        )->fetchColumn();
        if ($clash !== false) return false;

        DB::run('UPDATE categories SET name = :name WHERE id = :id', [':name' => $newName, ':id' => $id]);
        return true;
    }

    /**
     * Delete a category — only allowed when no tool currently carries it (a category still
     * in use should be cleared via its tools, not deleted out from under them). category_access
     * and notification_badges rows referencing it are cleaned up automatically by the FK
     * ON DELETE CASCADE set up in the categories migration.
     *
     * Since no tool may carry this category (checked above), the tool_access visibility path
     * can never have matched it anyway — the only notifications affected are the ones that had
     * it as a direct badge, which cascade-delete along with the category. Those notifications'
     * notification_recipients rows need a fresh recompute (their badge set just shrank).
     */
    public function delete(int $id): bool
    {
        $toolCount = (int) DB::run(
            'SELECT COUNT(*) FROM tools WHERE category_id = :id',
            [':id' => $id]
        )->fetchColumn();
        if ($toolCount > 0) return false;

        $affectedNotifIds = array_column(
            DB::run('SELECT notification_id FROM notification_badges WHERE category_id = :id', [':id' => $id])->fetchAll(),
            'notification_id'
        );

        DB::run('DELETE FROM categories WHERE id = :id', [':id' => $id]);

        $nm = new NotificationModel();
        foreach ($affectedNotifIds as $nid) {
            $nm->refreshRecipientsForNotification((int) $nid);
        }

        return true;
    }
}
