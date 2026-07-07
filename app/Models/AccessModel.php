<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// AccessModel — مدیریت دسترسی دو سطحی
//   سطح ۱: tool_access    — دسترسی مستقیم کاربر ↔ ابزار
//   سطح ۲: category_access — دسترسی گروهی کاربر ↔ badge
// ═══════════════════════════════════════════════════════════

class AccessModel
{
    // ── Tool-level (سطح ۱) ──────────────────────────────────

    /** ID ابزارهایی که کاربر دسترسی مستقیم دارد */
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

    // ── Category-level (سطح ۲) ──────────────────────────────

    /** badge هایی که کاربر دسترسی گروهی دارد */
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

    /** دریافت هر دو سطح دسترسی با یک فراخوانی */
    public function getAll(int $userId): array
    {
        return [
            'tool_ids' => $this->getToolIds($userId),
            'badges'   => $this->getBadges($userId),
        ];
    }

    /**
     * ذخیره هر دو سطح دسترسی در یک transaction
     * ابتدا همه دسترسی‌های قبلی پاک، سپس جدید نوشته می‌شود
     */
    public function setAll(int $userId, array $toolIds, array $badges): bool
    {
        $pdo = DB::get();
        $pdo->beginTransaction();

        try {
            // پاک کردن دسترسی‌های قبلی
            DB::run('DELETE FROM tool_access     WHERE user_id = :uid', [':uid' => $userId]);
            DB::run('DELETE FROM category_access WHERE user_id = :uid', [':uid' => $userId]);

            // ثبت دسترسی مستقیم به ابزارها (یک INSERT چندردیفی به‌جای یک اجرا به‌ازای هر ردیف)
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

            // ثبت دسترسی گروهی به badge ها (whitelist شده، یک INSERT چندردیفی)
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

    /** لیست badge های موجود در سیستم (از جدول tools) */
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
