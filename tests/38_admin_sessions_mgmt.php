<?php
declare(strict_types=1);

if (!isset($cfg)) $cfg = require __DIR__ . '/bootstrap.php';
$BASE = $cfg['test']['base_url'];
$ACC  = $cfg['test']['accounts'];

Assert::group('38_admin_sessions_mgmt');

Assert::test('list_sessions → شامل نشست ادمین جاری', function () use ($BASE, $ACC) {
    $http = admin_http($BASE, $ACC);
    $res = $http->get('/admin.php?api=list_sessions');
    Assert::jsonOk($res, 'list_sessions باید ok:true بدهد');
    Assert::true(isset($res['json']['current_id']), 'باید current_id داشته باشد');
});

Assert::test('save_session_ttl خارج از بازه (0 یا 721) → رد می‌شود', function () use ($BASE, $ACC) {
    $http = admin_http($BASE, $ACC);
    $before = DB::run("SELECT svalue FROM app_settings WHERE skey='session_ttl_hours'")->fetchColumn();
    $res = $http->postJson('/admin.php?api=save_session_ttl', ['session_ttl_hours' => 0]);
    Assert::jsonFail($res, 'مقدار 0 باید رد شود');
    $res2 = $http->postJson('/admin.php?api=save_session_ttl', ['session_ttl_hours' => 721]);
    Assert::jsonFail($res2, 'مقدار 721 باید رد شود');
    $after = DB::run("SELECT svalue FROM app_settings WHERE skey='session_ttl_hours'")->fetchColumn();
    Assert::eq($before, $after, 'مقدار نامعتبر نباید تنظیمات را تغییر داده باشد');
});

Assert::test('terminate_session با شناسه معتبر → نشست واقعا از DB حذف می‌شود', function () use ($BASE, $ACC) {
    $passX = 'ZzTest!SessX2026!';
    $userX = Fixtures::uniq('sessx');
    Fixtures::createUser(['username' => $userX, 'password_hash' => password_hash($passX, PASSWORD_BCRYPT)]);

    $httpX = new HttpClient($BASE);
    $httpX->loginAs($userX, $passX);
    $rowsBefore = DB::run("SELECT id FROM sessions WHERE user_id = (SELECT id FROM users WHERE username=:u)", [':u' => $userX])->fetchAll();
    Assert::true(count($rowsBefore) >= 1, 'باید حداقل یک نشست فعال برای کاربر X وجود داشته باشد');
    if (count($rowsBefore) < 1) return;

    $sid = $rowsBefore[0]['id'];
    $httpAdmin = admin_http($BASE, $ACC);
    $res = $httpAdmin->postJson('/admin.php?api=terminate_session', ['session_id' => $sid]);
    Assert::jsonOk($res, 'terminate_session باید موفق باشد');

    $rowsAfter = DB::run('SELECT id FROM sessions WHERE id=:id', [':id' => $sid])->fetch();
    Assert::true($rowsAfter === false, 'نشست باید واقعا از DB حذف شده باشد');
});

Assert::test('terminate_user_sessions روی خود ادمین → نشست جاری او دست‌نخورده می‌ماند (except)', function () use ($BASE, $ACC) {
    $http = admin_http($BASE, $ACC);
    $adminRow = Fixtures::findUserByUsername($ACC['admin']['username']);
    $res = $http->postJson('/admin.php?api=terminate_user_sessions', ['user_id' => $adminRow['id']]);
    Assert::jsonOk($res, 'terminate_user_sessions روی خود ادمین باید موفق باشد');
    // اگر نشست جاری admin.php حذف شده بود، درخواست بعدی 401 می‌گرفت
    $res2 = $http->get('/admin.php?api=list_sessions');
    Assert::jsonOk($res2, 'نشست جاری ادمین باید بعد از عملیات هنوز معتبر باشد (except session_id فعلی)');
});

Fixtures::deleteUsersByPrefix(false);
