<?php
declare(strict_types=1);

if (!isset($cfg)) $cfg = require __DIR__ . '/bootstrap.php';
$BASE = $cfg['test']['base_url'];
$ACC  = $cfg['test']['accounts'];

Assert::group('39_tool_access_visibility');

// Cards have no public/private flag — a user only sees a card through their access role:
// a direct tool grant (role_tool_access) or a grant on the card's category (role_category_access).
$categoryName = Fixtures::uniq('cat');
$toolId       = Fixtures::createTool(['badge' => $categoryName]);

Assert::test('کاربر بدون نقش دسترسی → کارت را نمی‌بیند', function () use ($BASE, $toolId) {
    $uid = Fixtures::createUser();
    $row = DB::run('SELECT username FROM users WHERE id=:id', [':id' => $uid])->fetch();
    $http = new HttpClient($BASE);
    $http->loginAs($row['username'], 'ZzTest!Fixture2026');
    $res = $http->get('/api.php?action=tools');
    Assert::jsonOk($res, 'tools باید ok:true بدهد');
    $ids = array_map(fn($t) => (int) $t['id'], $res['json']['tools'] ?? []);
    Assert::true(!in_array($toolId, $ids, true), 'کاربر بدون هیچ دسترسی‌ای نباید کارت را ببیند');
});

Assert::test('کاربری که نقشش فقط همین ابزار را مستقیم دارد (بدون دسته) → کارت را می‌بیند', function () use ($BASE, $toolId) {
    $uid = Fixtures::createUser();
    Fixtures::assignRole($uid, Fixtures::createRole([], [$toolId]));

    $row = DB::run('SELECT username FROM users WHERE id=:id', [':id' => $uid])->fetch();
    $http = new HttpClient($BASE);
    $http->loginAs($row['username'], 'ZzTest!Fixture2026');
    $res = $http->get('/api.php?action=tools');
    Assert::jsonOk($res, 'tools باید ok:true بدهد');
    $ids = array_map(fn($t) => (int) $t['id'], $res['json']['tools'] ?? []);
    Assert::true(in_array($toolId, $ids, true), 'کاربر با ابزار مستقیم در نقشش باید کارت را ببیند');
});

Assert::test('کاربری که نقشش فقط دسته این ابزار را دارد (بدون ابزار مستقیم) → کارت را می‌بیند', function () use ($BASE, $toolId, $categoryName) {
    $uid = Fixtures::createUser();
    Fixtures::assignRole($uid, Fixtures::createRole([$categoryName]));

    $row = DB::run('SELECT username FROM users WHERE id=:id', [':id' => $uid])->fetch();
    $http = new HttpClient($BASE);
    $http->loginAs($row['username'], 'ZzTest!Fixture2026');
    $res = $http->get('/api.php?action=tools');
    Assert::jsonOk($res, 'tools باید ok:true بدهد');
    $ids = array_map(fn($t) => (int) $t['id'], $res['json']['tools'] ?? []);
    Assert::true(in_array($toolId, $ids, true), 'کاربر با دسته در نقشش باید کارت را ببیند');
});

// Admins are not exempt from access roles: their dashboard grid comes from the same
// allForUser() query as everyone else, so a role can restrict them too. Panel privileges (admin.php) stay untouched — only the cards are filtered.
Assert::test('کاربر مدیر بدون دسترسی به کارت → آن را در داشبورد نمی‌بیند', function () use ($BASE, $toolId) {
    $uid = Fixtures::createUser(['role' => 'admin']);
    $row = DB::run('SELECT username FROM users WHERE id=:id', [':id' => $uid])->fetch();
    $http = new HttpClient($BASE);
    $http->loginAs($row['username'], 'ZzTest!Fixture2026');
    $res = $http->get('/api.php?action=tools');
    Assert::jsonOk($res, 'tools باید ok:true بدهد');
    $ids = array_map(fn($t) => (int) $t['id'], $res['json']['tools'] ?? []);
    Assert::true(!in_array($toolId, $ids, true), 'مدیر بدون دسترسی هم نباید کارت را ببیند');
});

Assert::test('کاربر مدیر با نقشی که این ابزار را دارد → کارت را می‌بیند', function () use ($BASE, $toolId) {
    $uid = Fixtures::createUser(['role' => 'admin']);
    Fixtures::assignRole($uid, Fixtures::createRole([], [$toolId]));

    $row = DB::run('SELECT username FROM users WHERE id=:id', [':id' => $uid])->fetch();
    $http = new HttpClient($BASE);
    $http->loginAs($row['username'], 'ZzTest!Fixture2026');
    $res = $http->get('/api.php?action=tools');
    Assert::jsonOk($res, 'tools باید ok:true بدهد');
    $ids = array_map(fn($t) => (int) $t['id'], $res['json']['tools'] ?? []);
    Assert::true(in_array($toolId, $ids, true), 'مدیر با ابزار در نقشش باید کارت را ببیند');
});

Fixtures::deleteUsersByPrefix(false);
Fixtures::deleteRolesByPrefix();
Fixtures::deleteToolsByPrefix();
