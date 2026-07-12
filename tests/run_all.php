<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// run_all.php — run the full API test suite in phase order
// prerequisite: php -S 127.0.0.1:8080 -t . dev-router.php running in another terminal
// and tests\seed\001_test_accounts.php having run at least once.
// ═══════════════════════════════════════════════════════════

$cfg = require __DIR__ . '/bootstrap.php';

foreach (['zztest_admin', 'zztest_user', 'zztest_locked'] as $u) {
    if (Fixtures::findUserByUsername($u) === null) {
        fwrite(STDERR, "حساب‌های تستی seed نشده‌اند. ابتدا اجرا کنید:\n  php tests\\seed\\001_test_accounts.php\n");
        exit(1);
    }
}

$files = glob(__DIR__ . '/[0-9][0-9]_*.php');
sort($files);

foreach ($files as $file) {
    fwrite(STDOUT, "\n▶ " . basename($file) . "\n");
    require $file;
}

Reporter::printSummary('نتیجه کلی run_all.php');

$sweep = Fixtures::sweepAll();
$leftover = array_sum($sweep);
if ($leftover > 0) {
    fwrite(STDOUT, "\n[sweep نهایی] ردیف‌های zztest_ باقی‌مانده پاک شدند: " . json_encode($sweep, JSON_UNESCAPED_UNICODE) . "\n");
} else {
    fwrite(STDOUT, "\n[sweep نهایی] هیچ ردیف zztest_ باقی‌مانده‌ای یافت نشد.\n");
}

$reportFile = Reporter::writeReport(__DIR__ . '/results');
fwrite(STDOUT, "گزارش کامل: {$reportFile}\n");

exit(Assert::$fail > 0 ? 1 : 0);
