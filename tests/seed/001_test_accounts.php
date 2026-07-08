<?php
// ═══════════════════════════════════════════════════════════
// seed/001_test_accounts.php — ساخت/ری‌ست یک‌باره‌ی حساب‌های تستی ثابت
// اجرا (دستی، یک‌بار قبل از اولین اجرای مجموعه تست):
//   php tests\seed\001_test_accounts.php
// این اسکریپت idempotent است: اجرای مجدد فقط رمز/نقش/وضعیت را ری‌ست می‌کند.
// ═══════════════════════════════════════════════════════════
declare(strict_types=1);

$cfg = require __DIR__ . '/../bootstrap.php';
$accounts = $cfg['test']['accounts'];

$adminId  = Fixtures::ensureFixedAccount($accounts['admin']['username'],  $accounts['admin']['password'],  'admin', 1);
$userId   = Fixtures::ensureFixedAccount($accounts['user']['username'],   $accounts['user']['password'],   'user',  1);
$lockedId = Fixtures::ensureFixedAccount($accounts['locked']['username'], $accounts['locked']['password'], 'user',  0);

echo "zztest_admin  → id={$adminId}\n";
echo "zztest_user   → id={$userId}\n";
echo "zztest_locked → id={$lockedId} (is_active=0)\n";
echo "seed تکمیل شد.\n";
