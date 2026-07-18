<?php
declare(strict_types=1);

if (!isset($cfg)) $cfg = require __DIR__ . '/bootstrap.php';
$BASE = $cfg['test']['base_url'];
$ACC  = $cfg['test']['accounts'];

Assert::group('39_tool_access_visibility');

// Cards have no public/private flag — a non-admin user only sees a card via
// tool_access (direct grant) or category_access (grant on the card's category).
$categoryName = Fixtures::uniq('cat');
$toolId       = Fixtures::createTool(['badge' => $categoryName]);

Assert::test('کاربر بدون tool_access و بدون category_access → کارت را نمی‌بیند', function () use ($BASE, $toolId) {
    $uid = Fixtures::createUser();
    $row = DB::run('SELECT username FROM users WHERE id=:id', [':id' => $uid])->fetch();
    $http = new HttpClient($BASE);
    $http->loginAs($row['username'], 'ZzTest!Fixture2026');
    $res = $http->get('/api.php?action=tools');
    Assert::jsonOk($res, 'tools باید ok:true بدهد');
    $ids = array_map(fn($t) => (int) $t['id'], $res['json']['tools'] ?? []);
    Assert::true(!in_array($toolId, $ids, true), 'کاربر بدون هیچ دسترسی‌ای نباید کارت را ببیند');
});

Assert::test('کاربری که فقط tool_access مستقیم دارد (بدون category_access) → کارت را می‌بیند', function () use ($BASE, $ACC, $toolId) {
    $uid = Fixtures::createUser();
    $admin = admin_http($BASE, $ACC);
    $setRes = $admin->postJson('/admin.php?api=set_access', ['user_id' => $uid, 'tool_ids' => [$toolId], 'badges' => []]);
    Assert::jsonOk($setRes, 'set_access (فقط tool_access) باید موفق باشد');

    $row = DB::run('SELECT username FROM users WHERE id=:id', [':id' => $uid])->fetch();
    $http = new HttpClient($BASE);
    $http->loginAs($row['username'], 'ZzTest!Fixture2026');
    $res = $http->get('/api.php?action=tools');
    Assert::jsonOk($res, 'tools باید ok:true بدهد');
    $ids = array_map(fn($t) => (int) $t['id'], $res['json']['tools'] ?? []);
    Assert::true(in_array($toolId, $ids, true), 'کاربر با tool_access مستقیم باید کارت را ببیند');
});

Assert::test('کاربری که فقط category_access گروهی دارد (بدون tool_access) → کارت را می‌بیند', function () use ($BASE, $ACC, $toolId, $categoryName) {
    $uid = Fixtures::createUser();
    $admin = admin_http($BASE, $ACC);
    $setRes = $admin->postJson('/admin.php?api=set_access', ['user_id' => $uid, 'tool_ids' => [], 'badges' => [$categoryName]]);
    Assert::jsonOk($setRes, 'set_access (فقط category_access) باید موفق باشد');

    $row = DB::run('SELECT username FROM users WHERE id=:id', [':id' => $uid])->fetch();
    $http = new HttpClient($BASE);
    $http->loginAs($row['username'], 'ZzTest!Fixture2026');
    $res = $http->get('/api.php?action=tools');
    Assert::jsonOk($res, 'tools باید ok:true بدهد');
    $ids = array_map(fn($t) => (int) $t['id'], $res['json']['tools'] ?? []);
    Assert::true(in_array($toolId, $ids, true), 'کاربر با category_access گروهی باید کارت را ببیند');
});

Fixtures::deleteToolsByPrefix();
Fixtures::deleteUsersByPrefix(false);
