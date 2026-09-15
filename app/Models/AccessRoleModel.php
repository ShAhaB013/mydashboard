<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// AccessRoleModel — WHMCS-style access roles
//   A role = categories (every tool in them) + specific tools + hidden menus.
//   Every user has exactly one role (users.access_role_id); there are no
//   per-user exceptions — a different need means a different role.
// ═══════════════════════════════════════════════════════════

class AccessRoleModel
{
    /** All roles with their members and grants, for the roles page (a handful of queries, no N+1) */
    public function allWithSummary(): array
    {
        $roles = DB::run('SELECT id, name, description FROM access_roles ORDER BY name ASC')->fetchAll();
        if (!$roles) return [];

        $byRole = static function (array $rows, string $valueKey): array {
            $out = [];
            foreach ($rows as $r) {
                $out[(int) $r['role_id']][] = $valueKey === 'id' ? (int) $r[$valueKey] : $r[$valueKey];
            }
            return $out;
        };

        $members = DB::run(
            "SELECT access_role_id AS role_id, id,
                    COALESCE(NULLIF(display_name, ''), username) AS name, username, is_active
             FROM users WHERE access_role_id IS NOT NULL ORDER BY name ASC"
        )->fetchAll();
        $membersByRole = [];
        foreach ($members as $m) {
            $membersByRole[(int) $m['role_id']][] = [
                'id'        => (int) $m['id'],
                'name'      => $m['name'],
                'username'  => $m['username'],
                'is_active' => (bool) $m['is_active'],
            ];
        }

        $categories = $byRole(DB::run(
            'SELECT rca.role_id, c.name FROM role_category_access rca
             JOIN categories c ON c.id = rca.category_id ORDER BY c.name ASC'
        )->fetchAll(), 'name');
        $tools = $byRole(DB::run('SELECT role_id, tool_id AS id FROM role_tool_access')->fetchAll(), 'id');
        $menus = $byRole(DB::run('SELECT role_id, menu_key FROM role_hidden_menus')->fetchAll(), 'menu_key');

        return array_map(static function (array $r) use ($membersByRole, $categories, $tools, $menus): array {
            $id = (int) $r['id'];
            return [
                'id'           => $id,
                'name'         => $r['name'],
                'description'  => $r['description'],
                'members'      => $membersByRole[$id] ?? [],
                'badges'       => $categories[$id] ?? [],
                'tool_ids'     => $tools[$id] ?? [],
                'hidden_menus' => $menus[$id] ?? [],
            ];
        }, $roles);
    }

    /** Lightweight id/name list (user modal select) */
    public function allNames(): array
    {
        return array_map(
            static fn(array $r): array => ['id' => (int) $r['id'], 'name' => $r['name']],
            DB::run('SELECT id, name FROM access_roles ORDER BY name ASC')->fetchAll()
        );
    }

    public function exists(int $id): bool
    {
        return $id > 0 && DB::run('SELECT 1 FROM access_roles WHERE id = :id', [':id' => $id])->fetchColumn() !== false;
    }

    public function nameExists(string $name, int $excludeId = 0): bool
    {
        return DB::run(
            'SELECT 1 FROM access_roles WHERE name = :name AND id != :id',
            [':name' => $name, ':id' => $excludeId]
        )->fetchColumn() !== false;
    }

    public function memberCount(int $id): int
    {
        return (int) DB::run('SELECT COUNT(*) FROM users WHERE access_role_id = :id', [':id' => $id])->fetchColumn();
    }

    /**
     * Creates ($id = 0) or updates a role with its full grant set, in one transaction.
     * Every member's notification visibility is recomputed inside the same transaction,
     * so a failure rolls the access change back too. Returns the role id.
     *
     * @param string[] $badges   category names (unknown names are ignored)
     * @param int[]    $toolIds
     * @param string[] $hiddenMenus subset of MenuAccessModel::MENU_KEYS
     */
    public function save(int $id, string $name, string $description, array $badges, array $toolIds, array $hiddenMenus): int
    {
        $pdo = DB::get();
        $pdo->beginTransaction();
        try {
            if ($id > 0) {
                DB::run(
                    'UPDATE access_roles SET name = :name, description = :d WHERE id = :id',
                    [':name' => $name, ':d' => $description, ':id' => $id]
                );
                DB::run('DELETE FROM role_category_access WHERE role_id = :id', [':id' => $id]);
                DB::run('DELETE FROM role_tool_access     WHERE role_id = :id', [':id' => $id]);
                DB::run('DELETE FROM role_hidden_menus    WHERE role_id = :id', [':id' => $id]);
            } else {
                DB::run(
                    'INSERT INTO access_roles (name, description) VALUES (:name, :d)',
                    [':name' => $name, ':d' => $description]
                );
                $id = (int) $pdo->lastInsertId();
            }

            $categoryModel = new CategoryModel();
            $categoryIds   = [];
            foreach ($badges as $b) {
                $cid = $categoryModel->findIdByName((string) $b);
                if ($cid !== null) $categoryIds[] = $cid;
            }
            $this->insertPairs('role_category_access', 'category_id', $id, array_unique($categoryIds));

            $toolIds = array_unique(array_filter(array_map('intval', $toolIds), static fn(int $t): bool => $t > 0));
            if ($toolIds) {
                // Only ids of tools that still exist (a tool may have been deleted while the modal was open)
                $in = implode(',', $toolIds);
                $toolIds = array_map('intval', array_column(DB::run("SELECT id FROM tools WHERE id IN ($in)")->fetchAll(), 'id'));
            }
            $this->insertPairs('role_tool_access', 'tool_id', $id, $toolIds);

            $hiddenMenus = array_values(array_intersect(MenuAccessModel::MENU_KEYS, $hiddenMenus));
            $this->insertPairs('role_hidden_menus', 'menu_key', $id, $hiddenMenus);

            $this->refreshMembers($id);

            $pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Deletes a role — refused (false) while any user still has it */
    public function delete(int $id): bool
    {
        if ($this->memberCount($id) > 0) return false;
        DB::run('DELETE FROM access_roles WHERE id = :id', [':id' => $id]);
        return true;
    }

    /** Recomputes notification visibility for every member of a role */
    private function refreshMembers(int $roleId): void
    {
        $nm = new NotificationModel();
        foreach (DB::run('SELECT id FROM users WHERE access_role_id = :id', [':id' => $roleId])->fetchAll() as $u) {
            $nm->refreshRecipientsForUser((int) $u['id']);
        }
    }

    /** Single multi-row INSERT of (role_id, $column) pairs */
    private function insertPairs(string $table, string $column, int $roleId, array $values): void
    {
        $values = array_values($values);
        if (!$values) return;
        $placeholders = [];
        $params       = [];
        foreach ($values as $i => $v) {
            $placeholders[]     = "(:r{$i}, :v{$i})";
            $params[":r{$i}"]   = $roleId;
            $params[":v{$i}"]   = $v;
        }
        DB::run("INSERT IGNORE INTO {$table} (role_id, {$column}) VALUES " . implode(', ', $placeholders), $params);
    }
}
