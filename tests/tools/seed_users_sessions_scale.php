<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// seed_users_sessions_scale.php — large synthetic users + sessions dataset for
// verifying EXPLAIN/performance at scale (companion to seed_notifications_scale.php).
// Outside run_all.php (heavy, run manually only). Raw inserts, bypassing
// UserController::create()/DbSessionHandler on purpose — this dataset is for
// query/EXPLAIN performance on users/sessions listings, not for login correctness.
//
// run:     php tests\tools\seed_users_sessions_scale.php [users=5000] [sessions=5000]
// cleanup: php tests\tools\seed_users_sessions_scale.php --cleanup
// ═══════════════════════════════════════════════════════════

$cfg = require __DIR__ . '/../bootstrap.php';

const PREFIX = 'zztest_scale_user_';
const SESS_PREFIX = 'zztest_scale_sess_';
const BATCH  = 1000;

if (($argv[1] ?? '') === '--cleanup') {
    echo "پاک‌سازی کاربران/نشست‌های synthetic...\n";
    $ids = DB::run("SELECT id FROM users WHERE username LIKE :p", [':p' => PREFIX . '%'])->fetchAll(PDO::FETCH_COLUMN);
    $n = count($ids);
    if ($n > 0) {
        foreach (array_chunk($ids, 2000) as $chunk) {
            $in = implode(',', array_map('intval', $chunk));
            DB::run("DELETE FROM sessions WHERE user_id IN ($in)");
            DB::run("DELETE FROM notification_recipients WHERE user_id IN ($in)");
            DB::run("DELETE FROM notification_reads WHERE user_id IN ($in)");
            DB::run("DELETE FROM category_access WHERE user_id IN ($in)");
            DB::run("DELETE FROM tool_access WHERE user_id IN ($in)");
            DB::run("DELETE FROM users WHERE id IN ($in)");
        }
    }
    $sessLeft = DB::run("DELETE FROM sessions WHERE id LIKE :p", [':p' => SESS_PREFIX . '%'])->rowCount();
    $left = (int) DB::run("SELECT COUNT(*) c FROM users")->fetchColumn();
    echo "حذف شد: {$n} کاربر synthetic (+ {$sessLeft} نشست باقی‌مانده مستقل). تعداد باقی‌مانده در جدول users: {$left}\n";
    exit(0);
}

$totalUsers    = (int) ($argv[1] ?? 5000);
$totalSessions = (int) ($argv[2] ?? 5000);
$runId         = bin2hex(random_bytes(4)); // unique per run — lets this be re-run without --cleanup first

echo "درج {$totalUsers} کاربر synthetic...\n";
// hashed once, reused for every row — this dataset is for query/EXPLAIN performance, not login correctness
$hash = password_hash('ZzTest!Scale2026!', PASSWORD_BCRYPT);
$inserted = 0;
while ($inserted < $totalUsers) {
    $n = min(BATCH, $totalUsers - $inserted);
    $rows = [];
    $params = [];
    for ($i = 0; $i < $n; $i++) {
        $idx = $inserted + $i;
        $username = PREFIX . $runId . '_' . $idx;
        $rows[] = "(:u{$i}, :ph{$i}, :fn{$i}, :ln{$i}, :dn{$i}, 'user', 1)";
        $params[":u{$i}"]  = $username;
        $params[":ph{$i}"] = $hash;
        $params[":fn{$i}"] = 'کاربر';
        $params[":ln{$i}"] = 'تست';
        $params[":dn{$i}"] = $username;
    }
    DB::run(
        'INSERT INTO users (username, password_hash, first_name, last_name, display_name, role, is_active)
         VALUES ' . implode(',', $rows),
        $params
    );
    $inserted += $n;
    if ($inserted % 10000 === 0 || $inserted === $totalUsers) {
        echo "  users {$inserted}/{$totalUsers}\n";
    }
}

echo "درج {$totalSessions} نشست synthetic (روی کاربران موجود، واقعی و synthetic، تصادفی)...\n";
$allUserIds = DB::run('SELECT id FROM users')->fetchAll(PDO::FETCH_COLUMN);
$now = time();
$inserted = 0;
while ($inserted < $totalSessions) {
    $n = min(BATCH, $totalSessions - $inserted);
    $rows = [];
    $params = [];
    for ($i = 0; $i < $n; $i++) {
        $idx = $inserted + $i;
        $uid = (int) $allUserIds[array_rand($allUserIds)];
        $lastSeen = $now - random_int(0, 30 * 24 * 3600); // spread over the last 30 days
        $rows[] = "(:id{$i}, :uid{$i}, '203.0.113.1', 'zztest-scale-agent', :pl{$i}, :ls{$i}, :exp{$i})";
        $params[":id{$i}"]  = SESS_PREFIX . $runId . '_' . bin2hex(random_bytes(6)) . '_' . $idx;
        $params[":uid{$i}"] = $uid;
        $params[":pl{$i}"]  = ''; // payload isn't read by the admin sessions listing — only id/user_id/ip/user_agent/last_seen/expires_at
        $params[":ls{$i}"]  = $lastSeen;
        $params[":exp{$i}"] = $lastSeen + 8 * 3600;
    }
    DB::run(
        'INSERT INTO sessions (id, user_id, ip, user_agent, payload, last_seen, expires_at)
         VALUES ' . implode(',', $rows),
        $params
    );
    $inserted += $n;
    if ($inserted % 10000 === 0 || $inserted === $totalSessions) {
        echo "  sessions {$inserted}/{$totalSessions}\n";
    }
}

$finalUsers    = (int) DB::run("SELECT COUNT(*) c FROM users")->fetchColumn();
$finalSessions = (int) DB::run("SELECT COUNT(*) c FROM sessions WHERE expires_at > :now", [':now' => $now])->fetchColumn();
echo "تکمیل شد. users={$finalUsers} (کل جدول), sessions فعال={$finalSessions}\n";
echo "برای پاک‌سازی: php tests\\tools\\seed_users_sessions_scale.php --cleanup\n";
