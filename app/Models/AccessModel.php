<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// AccessModel — manages two-level access control
//   Level 1: tool_access    — direct user <-> tool access
//   Level 2: category_access — group user <-> badge access
// ═══════════════════════════════════════════════════════════

class AccessModel
{
    // ── Tool-level (level 1) ────────────────────────────────

    /** IDs of tools the user has direct access to */
    public function getToolIds(int $userId): array
    {
        return array_column(
            DB::run(
                'SELECT tool_id FROM tool_access WHERE user_id = :uid',
                [':uid' => $userId]
            )->fetchAll(),
            'tool_id'
        );
    }

    // ── Category-level (level 2) ────────────────────────────

    /** Badges the user has group access to */
    public function getBadges(int $userId): array
    {
        return array_column(
            DB::run(
                'SELECT c.name AS badge
                 FROM category_access ca
                 JOIN categories c ON c.id = ca.category_id
                 WHERE ca.user_id = :uid
                 ORDER BY c.name ASC',
                [':uid' => $userId]
            )->fetchAll(),
            'badge'
        );
    }

    // ── Combined ────────────────────────────────────────────

    /** Gets both access levels in a single call */
    public function getAll(int $userId): array
    {
        return [
            'tool_ids' => $this->getToolIds($userId),
            'badges'   => $this->getBadges($userId),
        ];
    }

    /**
     * Saves both access levels in a single transaction
     * First all previous access is cleared, then the new access is written
     */
    public function setAll(int $userId, array $toolIds, array $badges): bool
    {
        $pdo = DB::get();
        $pdo->beginTransaction();

        try {
            // Clear previous access
            DB::run('DELETE FROM tool_access     WHERE user_id = :uid', [':uid' => $userId]);
            DB::run('DELETE FROM category_access WHERE user_id = :uid', [':uid' => $userId]);

            // Insert direct tool access (a single multi-row INSERT instead of one execution per row)
            if (!empty($toolIds)) {
                $placeholders = [];
                $params       = [];
                foreach (array_values($toolIds) as $i => $tid) {
                    $placeholders[] = "(:uid{$i}, :tid{$i})";
                    $params[":uid{$i}"] = $userId;
                    $params[":tid{$i}"] = (int) $tid;
                }
                DB::run(
                    'INSERT IGNORE INTO tool_access (user_id, tool_id) VALUES ' . implode(', ', $placeholders),
                    $params
                );
            }

            // Insert group access to categories (whitelisted, a single multi-row INSERT)
            $categoryModel = new CategoryModel();
            $categoryIds   = [];
            foreach ($badges as $b) {
                $categoryId = $categoryModel->findIdByName((string) $b);
                if ($categoryId !== null) $categoryIds[] = $categoryId;
            }
            $categoryIds = array_values(array_unique($categoryIds));
            if (!empty($categoryIds)) {
                $placeholders = [];
                $params       = [];
                foreach ($categoryIds as $i => $categoryId) {
                    $placeholders[] = "(:uid{$i}, :cid{$i})";
                    $params[":uid{$i}"] = $userId;
                    $params[":cid{$i}"] = $categoryId;
                }
                DB::run(
                    'INSERT IGNORE INTO category_access (user_id, category_id) VALUES ' . implode(', ', $placeholders),
                    $params
                );
            }

            // Access just changed — recompute which notifications this user can now see
            // (notification_recipients is a write-time materialization of this exact
            // resolution; see NotificationModel::refreshRecipientsForUser). Runs inside
            // the same transaction, so a failure here rolls back the access change too.
            (new NotificationModel())->refreshRecipientsForUser($userId);

            $pdo->commit();
            return true;

        } catch (Exception $e) {
            $pdo->rollBack();
            return false;
        }
    }
}
