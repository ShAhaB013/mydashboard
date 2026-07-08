<?php
declare(strict_types=1);

if (!isset($cfg)) $cfg = require __DIR__ . '/bootstrap.php';
$BASE = $cfg['test']['base_url'];
$ACC  = $cfg['test']['accounts'];

Assert::group('61_notifications_bell_cap');

// ۱۲۰ اعلان عمومی مصنوعی (بیشتر از BELL_CAP=100) با ترکیب خوانده/ناخوانده برای زد_تست_یوزر
$passU = $ACC['user']['password'];
$uid   = (int) DB::run('SELECT id FROM users WHERE username=:u', [':u' => $ACC['user']['username']])->fetchColumn();

$ids = [];
$now = time();
for ($i = 0; $i < 120; $i++) {
    $id = Fixtures::createNotification([
        'title' => Fixtures::uniq('bell' . $i),
        'is_public' => 1, 'target_all_users' => 1,
        'created_at' => date('Y-m-d H:i:s', $now - $i), // نزولی، جدیدترین اول
    ]);
    $ids[] = $id;
    // نیمی از آن‌ها را از قبل خوانده‌شده علامت بزن
    if ($i % 2 === 0) {
        DB::run('INSERT INTO notification_reads (user_id, notification_id, read_at) VALUES (:u,:n,NOW())', [':u' => $uid, ':n' => $id]);
    }
}

Assert::test('فید زنگوله مهمان هرگز بیش از ۱۰۰ آیتم برنمی‌گرداند', function () use ($BASE) {
    $http = new HttpClient($BASE);
    $res = $http->get('/api.php?action=notifications');
    Assert::jsonOk($res, 'notifications باید ok:true بدهد');
    Assert::true(count($res['json']['notifications'] ?? []) <= 100, 'تعداد آیتم‌های فید مهمان نباید از ۱۰۰ بیشتر شود', ['count' => count($res['json']['notifications'] ?? [])]);
});

Assert::test('فید زنگوله کاربر لاگین‌شده هرگز بیش از ۱۰۰ آیتم برنمی‌گرداند و ناخوانده‌ها اول می‌آیند', function () use ($BASE, $ACC) {
    $http = new HttpClient($BASE);
    $http->loginAs($ACC['user']['username'], $ACC['user']['password']);
    $res = $http->get('/api.php?action=notifications');
    Assert::jsonOk($res, 'notifications باید ok:true بدهد');
    $items = $res['json']['notifications'] ?? [];
    Assert::true(count($items) <= 100, 'تعداد آیتم‌ها نباید از ۱۰۰ بیشتر شود', ['count' => count($items)]);

    $seenRead = false;
    $orderOk = true;
    foreach ($items as $it) {
        if (($it['is_read'] ?? false) === true) {
            $seenRead = true;
        } elseif ($seenRead) {
            $orderOk = false; // یک ناخوانده بعد از یک خوانده دیده شد → ترتیب غلط
        }
    }
    Assert::true($orderOk, 'همه‌ی آیتم‌های ناخوانده باید قبل از خوانده‌ها بیایند');
});

// حذف اعلان‌ها به‌صورت خودکار ردیف‌های notification_reads وابسته را هم پاک می‌کند (ON DELETE CASCADE)
foreach ($ids as $id) DB::run('DELETE FROM notifications WHERE id=:id', [':id' => $id]);
Fixtures::deleteNotificationsByPrefix();

Assert::test('فید زنگوله: منقضی+خوانده حذف می‌شود، منقضی+ناخوانده و فعال+خوانده باقی می‌مانند', function () use ($BASE, $ACC, $uid) {
    $past = time() - 3600; // منقضی

    $expiredRead   = Fixtures::createNotification(['title' => Fixtures::uniq('exp_read'), 'is_public' => 1, 'target_all_users' => 1, 'expires_at' => $past]);
    $expiredUnread = Fixtures::createNotification(['title' => Fixtures::uniq('exp_unread'), 'is_public' => 1, 'target_all_users' => 1, 'expires_at' => $past]);
    $activeRead    = Fixtures::createNotification(['title' => Fixtures::uniq('act_read'), 'is_public' => 1, 'target_all_users' => 1, 'expires_at' => 0]);

    DB::run('INSERT INTO notification_reads (user_id, notification_id, read_at) VALUES (:u,:n,NOW())', [':u' => $uid, ':n' => $expiredRead]);
    DB::run('INSERT INTO notification_reads (user_id, notification_id, read_at) VALUES (:u,:n,NOW())', [':u' => $uid, ':n' => $activeRead]);

    $http = new HttpClient($BASE);
    $http->loginAs($ACC['user']['username'], $ACC['user']['password']);
    $res = $http->get('/api.php?action=notifications');
    Assert::jsonOk($res, 'notifications باید ok:true بدهد');
    $ids = array_column($res['json']['notifications'] ?? [], 'id');

    Assert::true(!in_array($expiredRead, $ids, true), 'اعلان منقضی+خوانده نباید در فید زنگوله باشد');
    Assert::true(in_array($expiredUnread, $ids, true), 'اعلان منقضی+ناخوانده باید در فید زنگوله باقی بماند');
    Assert::true(in_array($activeRead, $ids, true), 'اعلان فعال+خوانده (غیرمنقضی) باید در فید زنگوله باقی بماند');

    foreach ([$expiredRead, $expiredUnread, $activeRead] as $id) DB::run('DELETE FROM notifications WHERE id=:id', [':id' => $id]);
    Fixtures::deleteNotificationsByPrefix();
});
