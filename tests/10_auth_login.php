<?php
declare(strict_types=1);

if (!isset($cfg)) $cfg = require __DIR__ . '/bootstrap.php';
$BASE = $cfg['test']['base_url'];
$ACC  = $cfg['test']['accounts'];

Assert::group('10_auth_login');

Assert::test('لاگین موفق کاربر عادی → ok:true + csrf در HTML', function () use ($BASE, $ACC) {
    $http = new HttpClient($BASE);
    $res  = $http->loginAs($ACC['user']['username'], $ACC['user']['password']);
    Assert::jsonOk($res, 'لاگین کاربر تستی باید موفق باشد');
    Assert::true($http->csrfToken() !== null, 'CSRF token باید از HTML بعد از لاگین استخراج شود');
});

Assert::test('لاگین موفق ادمین → is_admin:true', function () use ($BASE, $ACC) {
    $http = new HttpClient($BASE);
    $res  = $http->loginAs($ACC['admin']['username'], $ACC['admin']['password']);
    Assert::jsonOk($res, 'لاگین ادمین باید موفق باشد');
    Assert::eq(true, $res['json']['is_admin'] ?? null, 'is_admin باید true باشد');
});

Assert::test('رمز اشتباه → ok:false، بدون افشای وجود کاربر', function () use ($BASE, $ACC) {
    $http = new HttpClient($BASE);
    $res  = $http->postJson('/api.php?action=login', ['username' => $ACC['user']['username'], 'password' => 'wrong-pass-xxx'], [], false);
    Assert::jsonFail($res, 'رمز اشتباه باید ok:false برگرداند');
    Assert::eq('نام کاربری یا رمز عبور اشتباه است', $res['json']['msg'] ?? '', 'پیام خطا باید عمومی باشد (ضد enumeration)');
});

Assert::test('نام‌کاربری ناموجود → همان پیام عمومی (ضد enumeration)', function () use ($BASE) {
    $http = new HttpClient($BASE);
    $res  = $http->postJson('/api.php?action=login', ['username' => Fixtures::uniq('nouser'), 'password' => 'anything123!A'], [], false);
    Assert::eq('نام کاربری یا رمز عبور اشتباه است', $res['json']['msg'] ?? '', 'پیام خطا باید با کاربر واقعی یکسان باشد');
});

Assert::test('کاربر غیرفعال (zztest_locked) نمی‌تواند لاگین کند', function () use ($BASE, $ACC) {
    $http = new HttpClient($BASE);
    $res  = $http->postJson('/api.php?action=login', ['username' => $ACC['locked']['username'], 'password' => $ACC['locked']['password']], [], false);
    Assert::jsonFail($res, 'کاربر غیرفعال نباید بتواند لاگین کند');
});

Assert::test('پارامترهای ناقص → خطای تمیز نه 500', function () use ($BASE) {
    $http = new HttpClient($BASE);
    $res  = $http->postJson('/api.php?action=login', ['username' => ''], [], false);
    Assert::true($res['status'] < 500, 'پارامتر ناقص نباید 500 بدهد', ['status' => $res['status']]);
    Assert::jsonFail($res, 'پارامتر ناقص باید ok:false برگرداند');
});

Assert::test('GET به‌جای POST → 405 (Method Not Allowed)', function () use ($BASE) {
    $http = new HttpClient($BASE);
    $res  = $http->get('/api.php?action=login');
    Assert::statusEq($res, 405, 'GET روی login باید 405 بدهد');
});

Assert::test('me بدون لاگین → logged_in:false', function () use ($BASE) {
    $http = new HttpClient($BASE);
    $res  = $http->get('/api.php?action=me');
    Assert::jsonOk($res, 'me باید ok:true بدهد حتی مهمان');
    Assert::eq(false, $res['json']['logged_in'] ?? null, 'مهمان باید logged_in:false داشته باشد');
});

Assert::test('me بعد از لاگین → logged_in:true + username صحیح', function () use ($BASE, $ACC) {
    $http = new HttpClient($BASE);
    $http->loginAs($ACC['user']['username'], $ACC['user']['password']);
    $res = $http->get('/api.php?action=me');
    Assert::eq(true, $res['json']['logged_in'] ?? null, 'بعد از لاگین باید logged_in:true باشد');
    Assert::eq($ACC['user']['username'], $res['json']['username'] ?? '', 'username باید مطابق کاربر لاگین‌شده باشد');
});

Assert::test('logout → نشست باطل می‌شود', function () use ($BASE, $ACC) {
    $http = new HttpClient($BASE);
    $http->loginAs($ACC['user']['username'], $ACC['user']['password']);
    $http->get('/api.php?action=logout');
    $res = $http->get('/api.php?action=me');
    Assert::eq(false, $res['json']['logged_in'] ?? null, 'بعد از logout باید logged_in:false باشد');
});

// پاک‌سازی احتمالی login_rate_limit مربوط به 127.0.0.1/scope=user بعد از تست‌های رمز اشتباه
Fixtures::deleteRateLimitByIp('127.0.0.1', 'user');
