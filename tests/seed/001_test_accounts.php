<?php
// ═══════════════════════════════════════════════════════════
// seed/001_test_accounts.php — one-time creation/reset of the fixed test accounts
// run (manually, once before the first test suite run):
//   php tests\seed\001_test_accounts.php
// this script is idempotent: rerunning it only resets password/role/status.
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
