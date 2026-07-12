<?php
declare(strict_types=1);

if (!isset($cfg)) $cfg = require __DIR__ . '/bootstrap.php';
$BASE = $cfg['test']['base_url'];

Assert::group('63_notifications_old_record_reachability');

$oldTitle = Fixtures::uniq('ancient');
$threeYearsAgo = date('Y-m-d H:i:s', time() - 3 * 365 * 24 * 3600);
$oldId = Fixtures::createNotification([
    'title' => $oldTitle, 'is_public' => 1, 'target_all_users' => 1,
    'created_at' => $threeYearsAgo,
]);

// also 105 new notifications to ensure the old record falls outside the BELL_CAP=100 window
$fillerIds = [];
for ($i = 0; $i < 105; $i++) {
    $fillerIds[] = Fixtures::createNotification([
        'title' => Fixtures::uniq('filler' . $i), 'is_public' => 1, 'target_all_users' => 1,
    ]);
}

Assert::test('اعلان خیلی قدیمی از فید محدود زنگوله (bell) غایب است', function () use ($BASE, $oldId) {
    $http = new HttpClient($BASE);
    $res = $http->get('/api.php?action=notifications');
    $ids = array_map(fn($n) => (int) $n['id'], $res['json']['notifications'] ?? []);
    Assert::true(!in_array($oldId, $ids, true), 'اعلان سه‌سال‌قبل نباید در فید زنگوله (که به ۱۰۰ مورد اخیر محدوده) دیده شود');
});

Assert::test('اعلان خیلی قدیمی از صفحه‌ی تاریخچه با جستجو قابل‌دسترسی است', function () use ($BASE, $oldTitle) {
    $http = new HttpClient($BASE);
    $res = $http->get('/notifications?q=' . urlencode($oldTitle));
    Assert::true($res['status'] < 500, 'جستجوی رکورد قدیمی نباید 500 بدهد');
    Assert::contains($res['body'], htmlspecialchars($oldTitle), 'عنوان اعلان قدیمی باید در نتیجه‌ی جستجوی تاریخچه دیده شود — یعنی رکوردهای قدیمی برای همیشه در دسترس می‌مانند');
});

foreach ($fillerIds as $id) DB::run('DELETE FROM notifications WHERE id=:id', [':id' => $id]);
DB::run('DELETE FROM notifications WHERE id=:id', [':id' => $oldId]);
Fixtures::deleteNotificationsByPrefix();
