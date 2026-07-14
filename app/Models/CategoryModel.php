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
}
