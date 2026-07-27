<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// MenuAccessModel — per-user header-menu visibility restrictions
//   Opt-out model: a row in user_menu_permissions means that menu is
//   HIDDEN for that user; no row (the default for every user) means visible.
// ═══════════════════════════════════════════════════════════

class MenuAccessModel
{
    /** Every restrictable menu key — keep in sync with the admin-panel toggles */
    public const MENU_KEYS = ['profile', 'notifications'];

    /** Menu keys currently hidden for this user */
    public function getHidden(int $userId): array
    {
        return array_column(
            DB::run(
                'SELECT menu_key FROM user_menu_permissions WHERE user_id = :uid',
                [':uid' => $userId]
            )->fetchAll(),
            'menu_key'
        );
    }

    /** Bulk: user_id => [hidden menu keys], for the admin users list (avoids N+1) */
    public function hiddenByUser(): array
    {
        $rows = DB::run('SELECT user_id, menu_key FROM user_menu_permissions')->fetchAll();
        $out  = [];
        foreach ($rows as $r) {
            $out[(int) $r['user_id']][] = $r['menu_key'];
        }
        return $out;
    }

    /** Replaces this user's hidden-menu set in one transaction (delete-then-reinsert) */
    public function setHidden(int $userId, array $menuKeys): bool
    {
        $menuKeys = array_values(array_intersect(self::MENU_KEYS, $menuKeys));

        $pdo = DB::get();
        $pdo->beginTransaction();

        try {
            DB::run('DELETE FROM user_menu_permissions WHERE user_id = :uid', [':uid' => $userId]);

            if (!empty($menuKeys)) {
                $placeholders = [];
                $params       = [];
                foreach ($menuKeys as $i => $key) {
                    $placeholders[] = "(:uid{$i}, :key{$i})";
                    $params[":uid{$i}"] = $userId;
                    $params[":key{$i}"] = $key;
                }
                DB::run(
                    'INSERT INTO user_menu_permissions (user_id, menu_key) VALUES ' . implode(', ', $placeholders),
                    $params
                );
            }

            $pdo->commit();
            return true;

        } catch (Exception $e) {
            $pdo->rollBack();
            return false;
        }
    }
}
