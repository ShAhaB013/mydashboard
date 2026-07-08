<?php
declare(strict_types=1);

if (!isset($cfg)) $cfg = require __DIR__ . '/bootstrap.php';
$BASE = $cfg['test']['base_url'];

Assert::group('00_smoke');

Assert::test('سرور dev در دسترس است', function () use ($BASE) {
    $http = new HttpClient($BASE);
    $res  = $http->get('/api.php?action=me');
    Assert::true($res['status'] > 0, 'اتصال به سرور dev برقرار نشد — php -S را اجرا کنید', ['error' => $res['error'] ?? null]);
});

Assert::test('DB در دسترس است', function () {
    $row = DB::run('SELECT 1 AS ok')->fetch();
    Assert::eq('1', (string) ($row['ok'] ?? ''), 'کوئری ساده DB شکست خورد');
});

Assert::test('حساب‌های تستی seed شده‌اند', function () {
    foreach (['zztest_admin', 'zztest_user', 'zztest_locked'] as $u) {
        $row = Fixtures::findUserByUsername($u);
        Assert::true($row !== null, "حساب {$u} یافت نشد — ابتدا php tests\\seed\\001_test_accounts.php را اجرا کنید");
    }
});
