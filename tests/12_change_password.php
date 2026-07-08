<?php
declare(strict_types=1);

if (!isset($cfg)) $cfg = require __DIR__ . '/bootstrap.php';
$BASE = $cfg['test']['base_url'];

Assert::group('12_change_password');

$username = Fixtures::uniq('chpw');
$oldPass  = 'ZzTest!Old2026!';
$uid = Fixtures::createUser(['username' => $username, 'password_hash' => password_hash($oldPass, PASSWORD_BCRYPT)]);

Assert::test('change_password بدون لاگین → 401', function () use ($BASE) {
    $http = new HttpClient($BASE);
    $res = $http->postJson('/api.php?action=change_password', ['current_password' => 'x', 'new_password' => 'y', 'confirm_password' => 'y'], [], false);
    Assert::statusEq($res, 401, 'بدون لاگین باید 401 بدهد');
});

Assert::test('change_password بدون CSRF header (لاگین‌شده) → 403', function () use ($BASE, $username, $oldPass) {
    $http = new HttpClient($BASE);
    $http->loginAs($username, $oldPass);
    $http->setCsrfToken(null); // عمدا حذف هدر CSRF
    $res = $http->postJson('/api.php?action=change_password', [
        'current_password' => $oldPass, 'new_password' => 'ZzTest!New2026!', 'confirm_password' => 'ZzTest!New2026!',
    ]);
    Assert::statusEq($res, 403, 'POST بدون CSRF header باید 403 بدهد');
});

Assert::test('change_password با رمز فعلی اشتباه → رد می‌شود', function () use ($BASE, $username, $oldPass) {
    $http = new HttpClient($BASE);
    $http->loginAs($username, $oldPass);
    $res = $http->postJson('/api.php?action=change_password', [
        'current_password' => 'wrong-current', 'new_password' => 'ZzTest!New2026!', 'confirm_password' => 'ZzTest!New2026!',
    ]);
    Assert::jsonFail($res, 'رمز فعلی اشتباه باید رد شود');
});

Assert::test('change_password با رمز جدید ضعیف → رد می‌شود', function () use ($BASE, $username, $oldPass) {
    $http = new HttpClient($BASE);
    $http->loginAs($username, $oldPass);
    $res = $http->postJson('/api.php?action=change_password', [
        'current_password' => $oldPass, 'new_password' => 'weak', 'confirm_password' => 'weak',
    ]);
    Assert::jsonFail($res, 'رمز ضعیف باید رد شود');
});

Assert::test('change_password موفق → ورود با رمز جدید کار می‌کند', function () use ($BASE, $username, $oldPass) {
    $http = new HttpClient($BASE);
    $http->loginAs($username, $oldPass);
    $newPass = 'ZzTest!New2026!';
    $res = $http->postJson('/api.php?action=change_password', [
        'current_password' => $oldPass, 'new_password' => $newPass, 'confirm_password' => $newPass,
    ]);
    Assert::jsonOk($res, 'تغییر رمز معتبر باید موفق باشد');

    $http2 = new HttpClient($BASE);
    $loginRes = $http2->loginAs($username, $newPass);
    Assert::jsonOk($loginRes, 'باید بتوان با رمز جدید لاگین کرد');
});

Fixtures::deleteUsersByPrefix(false);
