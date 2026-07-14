<?php
declare(strict_types=1);

if (!isset($cfg)) $cfg = require __DIR__ . '/bootstrap.php';
$BASE = $cfg['test']['base_url'];

Assert::group('22_notifications_feed_public');

$notifId = Fixtures::createNotification(['title' => Fixtures::uniq('feed'), 'is_public' => 1, 'target_all_users' => 1]);

Assert::test('notifications بدون لاگین → 401', function () use ($BASE) {
    $http = new HttpClient($BASE);
    $res = $http->get('/api.php?action=notifications');
    Assert::statusEq($res, 401, 'notifications بدون لاگین باید 401 بدهد');
});

Assert::test('notifications لاگین‌شده → شامل اعلان عمومی جدید', function () use ($BASE, $cfg, $notifId) {
    $acc = $cfg['test']['accounts']['user'];
    $http = new HttpClient($BASE);
    $http->loginAs($acc['username'], $acc['password']);
    $res = $http->get('/api.php?action=notifications');
    Assert::jsonOk($res, 'notifications باید ok:true بدهد');
    $ids = array_map(fn($n) => (int) $n['id'], $res['json']['notifications'] ?? []);
    Assert::true(in_array($notifId, $ids, true), 'اعلان تستی باید در فید کاربر باشد');
});

Assert::test('notifications → ETag/304', function () use ($BASE, $cfg) {
    $acc = $cfg['test']['accounts']['user'];
    $http = new HttpClient($BASE);
    $http->loginAs($acc['username'], $acc['password']);
    $res1 = $http->get('/api.php?action=notifications');
    $etag = $res1['headers']['etag'] ?? null;
    Assert::true($etag !== null, 'ETag باید ست شود');
    if ($etag === null) return;
    $res2 = $http->get('/api.php?action=notifications', ['If-None-Match: ' . $etag]);
    Assert::statusEq($res2, 304, 'با If-None-Match مطابق باید 304 بدهد');
});

Assert::test('unread_count بدون لاگین → ok:true, logged_in:false', function () use ($BASE) {
    $http = new HttpClient($BASE);
    $res = $http->get('/api.php?action=unread_count');
    Assert::jsonOk($res, 'unread_count باید ok:true بدهد');
    Assert::eq(false, $res['json']['logged_in'] ?? null, 'بدون لاگین باید logged_in:false باشد');
});

Assert::test('unread_count لاگین‌شده → logged_in:true', function () use ($BASE, $cfg) {
    $acc = $cfg['test']['accounts']['user'];
    $http = new HttpClient($BASE);
    $http->loginAs($acc['username'], $acc['password']);
    $res = $http->get('/api.php?action=unread_count');
    Assert::jsonOk($res, 'unread_count باید ok:true بدهد');
    Assert::eq(true, $res['json']['logged_in'] ?? null, 'کاربر لاگین‌شده باید logged_in:true باشد');
});

Assert::test('mark_read بدون لاگین → 401', function () use ($BASE) {
    $http = new HttpClient($BASE);
    $res = $http->postJson('/api.php?action=mark_read', ['notification_id' => 1], [], false);
    Assert::statusEq($res, 401, 'mark_read بدون لاگین باید 401 بدهد');
});

Assert::test('mark_read با notification_id نامعتبر (لاگین‌شده) → خطای تمیز', function () use ($BASE, $cfg) {
    $acc = $cfg['test']['accounts']['user'];
    $http = new HttpClient($BASE);
    $http->loginAs($acc['username'], $acc['password']);
    $res = $http->postJson('/api.php?action=mark_read', ['notification_id' => 0]);
    Assert::true($res['status'] < 500, 'notification_id نامعتبر نباید 500 بدهد');
    Assert::jsonFail($res, 'notification_id نامعتبر باید ok:false بدهد');
});

Assert::test('mark_read معتبر (لاگین‌شده) → ok:true', function () use ($BASE, $cfg, $notifId) {
    $acc = $cfg['test']['accounts']['user'];
    $http = new HttpClient($BASE);
    $http->loginAs($acc['username'], $acc['password']);
    $res = $http->postJson('/api.php?action=mark_read', ['notification_id' => $notifId]);
    Assert::jsonOk($res, 'mark_read با id معتبر باید موفق باشد');
});

Fixtures::deleteNotificationsByPrefix();
