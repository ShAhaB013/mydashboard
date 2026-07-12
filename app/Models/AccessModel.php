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
                'SELECT badge FROM category_access WHERE user_id = :uid',
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

            // Insert group access to badges (whitelisted, a single multi-row INSERT)
            $validBadges = $this->getAvailableBadges();
            $badges      = array_values(array_filter($badges, fn ($b) => in_array($b, $validBadges, true)));
            if (!empty($badges)) {
                $placeholders = [];
                $params       = [];
                foreach ($badges as $i => $badge) {
                    $placeholders[] = "(:uid{$i}, :badge{$i})";
                    $params[":uid{$i}"]   = $userId;
                    $params[":badge{$i}"] = $badge;
                }
                DB::run(
                    'INSERT IGNORE INTO category_access (user_id, badge) VALUES ' . implode(', ', $placeholders),
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

    // ── Utility ─────────────────────────────────────────────

    /** List of badges that exist in the system (from the tools table) */
    public function getAvailableBadges(): array
    {
        return array_column(
            DB::run(
                "SELECT DISTINCT badge FROM tools WHERE badge != '' ORDER BY badge ASC"
            )->fetchAll(),
            'badge'
        );
    }
}
