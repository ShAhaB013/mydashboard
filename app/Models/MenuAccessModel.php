<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// MenuAccessModel — header-menu visibility, decided by the user's access role
//   Opt-out model: a row in role_hidden_menus means that menu is HIDDEN for
//   every member of the role; no row means visible. A user without a role
//   sees every menu (and no tools — see ToolModel::allForUser).
// ═══════════════════════════════════════════════════════════

class MenuAccessModel
{
    /** Every restrictable menu key — keep in sync with the roles-page toggles */
    public const MENU_KEYS = ['profile', 'notifications'];

    /** Menu keys currently hidden for this user (through their access role) */
    public function getHidden(int $userId): array
    {
        return array_column(
            DB::run(
                'SELECT rhm.menu_key
                 FROM users u
                 JOIN role_hidden_menus rhm ON rhm.role_id = u.access_role_id
                 WHERE u.id = :uid',
                [':uid' => $userId]
            )->fetchAll(),
            'menu_key'
        );
    }
}
