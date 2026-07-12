<?php
// ═══════════════════════════════════════════════════════════
// seed_notifications_scale.php — a large synthetic dataset for verifying EXPLAIN/performance
// This script is outside run_all.php (heavy, run manually only).
//
// run:     php tests\tools\seed_notifications_scale.php [count=100000]
// cleanup: php tests\tools\seed_notifications_scale.php --cleanup
// ═══════════════════════════════════════════════════════════
declare(strict_types=1);

$cfg = require __DIR__ . '/../bootstrap.php';

const PREFIX = 'zztest_scale_';
const BADGE  = 'zztest_scale_badge';
const SCALE_USER = 'zztest_scaleuser';

if (($argv[1] ?? '') === '--cleanup') {
    echo "پاک‌سازی دیتاست مقیاس بزرگ...\n";
    $ids = DB::run("SELECT id FROM notifications WHERE title LIKE :p", [':p' => PREFIX . '%'])->fetchAll(PDO::FETCH_COLUMN);
    $n = count($ids);
    if ($n > 0) {
        foreach (array_chunk($ids, 5000) as $chunk) {
            $in = implode(',', array_map('intval', $chunk));
            DB::run("DELETE FROM notification_badges WHERE notification_id IN ($in)");
            DB::run("DELETE FROM notification_reads WHERE notification_id IN ($in)");
            DB::run("DELETE FROM notifications WHERE id IN ($in)");
        }
    }
    $userRow = Fixtures::findUserByUsername(SCALE_USER);
    if ($userRow) {
        DB::run('DELETE FROM category_access WHERE user_id = :id', [':id' => $userRow['id']]);
        DB::run('DELETE FROM notification_reads WHERE user_id = :id', [':id' => $userRow['id']]);
        DB::run('DELETE FROM users WHERE id = :id', [':id' => $userRow['id']]);
    }
    $left = (int) DB::run("SELECT COUNT(*) c FROM notifications")->fetchColumn();
    echo "حذف شد: {$n} اعلان. تعداد باقی‌مانده در جدول notifications: {$left} (باید نزدیک baseline اولیه باشد)\n";
    exit(0);
}

$total = (int) ($argv[1] ?? 100000);
$batchSize = 1000;

echo "ساخت کاربر synthetic و دسترسی badge...\n";
$scaleUserId = Fixtures::ensureFixedAccount(SCALE_USER, 'ZzTest!Scale2026!', 'user', 1);
DB::run('DELETE FROM category_access WHERE user_id = :id', [':id' => $scaleUserId]);
DB::run('INSERT INTO category_access (user_id, badge) VALUES (:uid, :b)', [':uid' => $scaleUserId, ':b' => BADGE]);

echo "درج {$total} اعلان مصنوعی در دسته‌های {$batchSize} تایی...\n";

$now = time();
$twoYears = 2 * 365 * 24 * 3600;
$inserted = 0;
$badgeCandidateIds = [];
$readCandidateIds = [];

while ($inserted < $total) {
    $n = min($batchSize, $total - $inserted);
    $rows = [];
    $params = [];
    for ($i = 0; $i < $n; $i++) {
        $idx = $inserted + $i;
        $ts = $now - random_int(0, $twoYears);
        $createdAt = date('Y-m-d H:i:s', $ts);
        $isPublic = random_int(1, 100) <= 60 ? 1 : 0;      // ~60% public
        $targetAll = ($isPublic === 0 && random_int(1, 100) <= 50) ? 1 : 0; // half of the rest are target_all
        $title = PREFIX . $idx;

        $rows[] = "(:t{$i}, :b{$i}, :ip{$i}, :ta{$i}, :ca{$i}, :ua{$i})";
        $params[":t{$i}"]  = $title;
        $params[":b{$i}"]  = 'scale body ' . $idx;
        $params[":ip{$i}"] = $isPublic;
        $params[":ta{$i}"] = $targetAll;
        $params[":ca{$i}"] = $createdAt;
        $params[":ua{$i}"] = $createdAt;
    }
    $sql = 'INSERT INTO notifications (title, body, is_public, target_all_users, created_at, updated_at) VALUES ' . implode(',', $rows);
    DB::run($sql, $params);

    $lastId = (int) DB::get()->lastInsertId();
    $firstId = $lastId - $n + 1;
    for ($id = $firstId; $id <= $lastId; $id++) {
        // ~10% of all rows (regardless of is_public/target_all) also get the synthetic badge
        // so the badge-matched UNION branch has meaningful data too.
        if (random_int(1, 10) === 1) $badgeCandidateIds[] = $id;
        // ~40% are marked read for the synthetic user (a realistic read/unread ratio)
        if (random_int(1, 10) <= 4) $readCandidateIds[] = $id;
    }

    $inserted += $n;
    if ($inserted % 10000 === 0 || $inserted === $total) {
        echo "  {$inserted}/{$total}\n";
    }
}

echo "درج notification_badges برای " . count($badgeCandidateIds) . " ردیف...\n";
foreach (array_chunk($badgeCandidateIds, 1000) as $chunk) {
    $rows = [];
    $params = [];
    foreach ($chunk as $i => $id) {
        $rows[] = "(:id{$i}, :b{$i})";
        $params[":id{$i}"] = $id;
        $params[":b{$i}"]  = BADGE;
    }
    DB::run('INSERT IGNORE INTO notification_badges (notification_id, badge) VALUES ' . implode(',', $rows), $params);
}

echo "درج notification_reads برای " . count($readCandidateIds) . " ردیف (کاربر synthetic)...\n";
foreach (array_chunk($readCandidateIds, 1000) as $chunk) {
    $rows = [];
    $params = [];
    foreach (array_values($chunk) as $i => $id) {
        $rows[] = "(:uid{$i}, :id{$i})";
        $params[":uid{$i}"] = $scaleUserId;
        $params[":id{$i}"]  = $id;
    }
    DB::run('INSERT IGNORE INTO notification_reads (user_id, notification_id) VALUES ' . implode(',', $rows), $params);
}

$finalCount = (int) DB::run("SELECT COUNT(*) c FROM notifications")->fetchColumn();
echo "تکمیل شد. تعداد کل ردیف‌های notifications الان: {$finalCount}\n";
echo "کاربر تست مقیاس: " . SCALE_USER . " (id={$scaleUserId})، badge=" . BADGE . "\n";
echo "برای پاک‌سازی: php tests\\tools\\seed_notifications_scale.php --cleanup\n";
