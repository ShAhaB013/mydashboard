<?php
declare(strict_types=1);

if (!isset($cfg)) $cfg = require __DIR__ . '/bootstrap.php';
$BASE = $cfg['test']['base_url'];
$ACC  = $cfg['test']['accounts'];

Assert::group('65_notification_visibility_category_access');

// A notification restricted to one category, and a tool carrying that same category —
// the two ways a user could plausibly gain visibility into the notification.
$categoryName = Fixtures::uniq('cat');
$toolId       = Fixtures::createTool(['badge' => $categoryName]);

$admin = admin_http($BASE, $ACC);
$notifTitle = Fixtures::uniq('catnotif');
$createRes  = $admin->postJson('/admin.php?api=create_notification', [
    'title'            => $notifTitle,
    'body'             => 'category-restricted body',
    'is_public'        => 0,
    'target_all_users' => 0,
    'badges'           => [$categoryName],
]);
Assert::jsonOk($createRes, 'ایجاد اعلان دسته‌بندی‌شده باید موفق باشد');
$notifId = (int) DB::run('SELECT id FROM notifications WHERE title=:t', [':t' => $notifTitle])->fetchColumn();

Assert::test('کاربری که فقط از طریق tool_access به یک کارت با این دسته دسترسی دارد (بدون category_access) → اعلان را می‌بیند', function () use ($BASE, $ACC, $toolId, $notifId) {
    $uid = Fixtures::createUser();
    $admin2 = admin_http($BASE, $ACC);
    $setRes = $admin2->postJson('/admin.php?api=set_access', ['user_id' => $uid, 'tool_ids' => [$toolId], 'badges' => []]);
    Assert::jsonOk($setRes, 'set_access (فقط tool_access) باید موفق باشد');

    $row = DB::run('SELECT username FROM users WHERE id=:id', [':id' => $uid])->fetch();
    $http = new HttpClient($BASE);
    $http->loginAs($row['username'], 'ZzTest!Fixture2026');
    $res = $http->get('/api.php?action=notifications');
    Assert::jsonOk($res, 'notifications باید ok:true بدهد');
    $ids = array_map(fn($n) => (int) $n['id'], $res['json']['notifications'] ?? []);
    Assert::true(in_array($notifId, $ids, true), 'کاربر با دسترسی فقط به کارت (tool_access) باید اعلان دسته‌ی همان کارت را ببیند');
});

Assert::test('کاربری که فقط از طریق category_access دسترسی گروهی دارد (بدون tool_access) → همچنان اعلان را می‌بیند', function () use ($BASE, $ACC, $notifId, $categoryName) {
    $uid = Fixtures::createUser();
    $admin2 = admin_http($BASE, $ACC);
    $setRes = $admin2->postJson('/admin.php?api=set_access', ['user_id' => $uid, 'tool_ids' => [], 'badges' => [$categoryName]]);
    Assert::jsonOk($setRes, 'set_access (فقط category_access) باید موفق باشد');

    $row = DB::run('SELECT username FROM users WHERE id=:id', [':id' => $uid])->fetch();
    $http = new HttpClient($BASE);
    $http->loginAs($row['username'], 'ZzTest!Fixture2026');
    $res = $http->get('/api.php?action=notifications');
    Assert::jsonOk($res, 'notifications باید ok:true بدهد');
    $ids = array_map(fn($n) => (int) $n['id'], $res['json']['notifications'] ?? []);
    Assert::true(in_array($notifId, $ids, true), 'کاربر با دسترسی گروهی به دسته (category_access) باید اعلان را ببیند');
});

Assert::test('کاربری که هیچ‌کدام از دو نوع دسترسی را ندارد → اعلان را نمی‌بیند', function () use ($BASE, $notifId) {
    $uid = Fixtures::createUser();
    $row = DB::run('SELECT username FROM users WHERE id=:id', [':id' => $uid])->fetch();
    $http = new HttpClient($BASE);
    $http->loginAs($row['username'], 'ZzTest!Fixture2026');
    $res = $http->get('/api.php?action=notifications');
    Assert::jsonOk($res, 'notifications باید ok:true بدهد');
    $ids = array_map(fn($n) => (int) $n['id'], $res['json']['notifications'] ?? []);
    Assert::true(!in_array($notifId, $ids, true), 'کاربر بدون هیچ دسترسی‌ای نباید اعلان محدود را ببیند');
});

DB::run('DELETE FROM notifications WHERE id=:id', [':id' => $notifId]);
Fixtures::deleteToolsByPrefix();
Fixtures::deleteUsersByPrefix(false);
