<?php
declare(strict_types=1);

if (!isset($cfg)) $cfg = require __DIR__ . '/bootstrap.php';
$BASE = $cfg['test']['base_url'];
$ACC  = $cfg['test']['accounts'];

Assert::group('34_admin_access_badges');

Assert::test('get_access با user_id نامعتبر → رد می‌شود', function () use ($BASE, $ACC) {
    $http = admin_http($BASE, $ACC);
    $res = $http->get('/admin.php?api=get_access&user_id=0');
    Assert::jsonFail($res, 'user_id نامعتبر باید رد شود');
});

Assert::test('set_access → tool_access/category_access دقیقا با درخواست مطابقت دارند (بدون ردیف یتیم)', function () use ($BASE, $ACC) {
    $http = admin_http($BASE, $ACC);
    // AccessModel::setAll فقط badge هایی را می‌پذیرد که در ستون tools.badge واقعا موجودند
    // (whitelist از DB، نه ورودی آزاد) — پس باید یک ابزار با badge واقعی بسازیم.
    $realBadge = Fixtures::uniq('badge');
    $uid   = Fixtures::createUser();
    $tool1 = Fixtures::createTool(['badge' => $realBadge]);
    $tool2 = Fixtures::createTool(['badge' => $realBadge]);

    $res1 = $http->postJson('/admin.php?api=set_access', ['user_id' => $uid, 'tool_ids' => [$tool1, $tool2], 'badges' => [$realBadge, 'nonexistent-badge-xyz']]);
    Assert::jsonOk($res1, 'set_access اول باید موفق باشد');

    $toolRows = DB::run('SELECT tool_id FROM tool_access WHERE user_id=:id', [':id' => $uid])->fetchAll();
    Assert::eq(2, count($toolRows), 'باید دقیقا ۲ ردیف tool_access وجود داشته باشد');
    $badgeRows1 = DB::run('SELECT badge FROM category_access WHERE user_id=:id', [':id' => $uid])->fetchAll();
    Assert::eq(1, count($badgeRows1), 'badge ناموجود باید بی‌صدا فیلتر شود؛ فقط badge واقعی ذخیره می‌شود (whitelist از tools.badge)');

    // فراخوانی دوم با زیرمجموعه — ردیف‌های اضافه باید حذف شوند (بدون orphan)
    $res2 = $http->postJson('/admin.php?api=set_access', ['user_id' => $uid, 'tool_ids' => [$tool1], 'badges' => [$realBadge]]);
    Assert::jsonOk($res2, 'set_access دوم باید موفق باشد');
    $toolRows2 = DB::run('SELECT tool_id FROM tool_access WHERE user_id=:id', [':id' => $uid])->fetchAll();
    Assert::eq(1, count($toolRows2), 'بعد از کاهش لیست، فقط ۱ ردیف باید باقی بماند (بدون ردیف یتیم)');
    $badgeRows2 = DB::run('SELECT badge FROM category_access WHERE user_id=:id', [':id' => $uid])->fetchAll();
    Assert::eq(1, count($badgeRows2), 'category_access هم باید دقیقا با درخواست جدید مطابقت داشته باشد');

    DB::run('DELETE FROM tools WHERE id IN (:a,:b)', [':a' => $tool1, ':b' => $tool2]);
});

Assert::test('badges → لیست badge های موجود برمی‌گردد', function () use ($BASE, $ACC) {
    $http = admin_http($BASE, $ACC);
    $res = $http->get('/admin.php?api=badges');
    Assert::jsonOk($res, 'badges باید ok:true بدهد');
    Assert::true(is_array($res['json']['badges'] ?? null), 'badges باید آرایه باشد');
});

Fixtures::deleteUsersByPrefix(false);
Fixtures::deleteToolsByPrefix();
