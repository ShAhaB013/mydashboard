<?php
declare(strict_types=1);

if (!isset($cfg)) $cfg = require __DIR__ . '/bootstrap.php';
$BASE = $cfg['test']['base_url'];
$ACC  = $cfg['test']['accounts'];

Assert::group('32_admin_icons_decos');

$iconsStore = new JsonStore($cfg['app']['files']['icons']);
$decosStore = new JsonStore($cfg['app']['files']['decos']);

Assert::test('save_icon با key نامعتبر → رد می‌شود', function () use ($BASE, $ACC) {
    $http = admin_http($BASE, $ACC);
    $res = $http->postJson('/admin.php?api=save_icon', ['key' => '1bad-start', 'path' => '<svg></svg>']);
    Assert::jsonFail($res, 'key که با عدد شروع شود باید رد شود');
});

Assert::test('save_icon معتبر → واقعا در فایل JSON نوشته می‌شود', function () use ($BASE, $ACC, $iconsStore) {
    $http = admin_http($BASE, $ACC);
    $key = Fixtures::uniq('icon');
    $res = $http->postJson('/admin.php?api=save_icon', ['key' => $key, 'path' => '<svg><path d="M0 0"/></svg>']);
    Assert::jsonOk($res, 'save_icon معتبر باید موفق باشد');
    $all = $iconsStore->all();
    Assert::true(isset($all[$key]), 'کلید آیکون باید در JSON ذخیره شده باشد');
    unset($all[$key]); $iconsStore->save($all);
});

Assert::test('delete_icon روی آیکون محافظت‌شده (star) → رد می‌شود', function () use ($BASE, $ACC) {
    $http = admin_http($BASE, $ACC);
    $res = $http->postJson('/admin.php?api=delete_icon', ['key' => 'star']);
    Assert::jsonFail($res, 'حذف star باید رد شود');
});

Assert::test('delete_icon روی آیکون استفاده‌شده در یک ابزار → رد می‌شود', function () use ($BASE, $ACC, $iconsStore) {
    $http = admin_http($BASE, $ACC);
    $key = Fixtures::uniq('used');
    $http->postJson('/admin.php?api=save_icon', ['key' => $key, 'path' => '<svg></svg>']);
    $toolId = Fixtures::createTool(['icon_key' => $key]);
    $res = $http->postJson('/admin.php?api=delete_icon', ['key' => $key]);
    Assert::jsonFail($res, 'آیکون در حال استفاده نباید حذف‌شدنی باشد');
    DB::run('DELETE FROM tools WHERE id=:id', [':id' => $toolId]);
    $all = $iconsStore->all(); unset($all[$key]); $iconsStore->save($all);
});

Assert::test('save_deco / delete_deco → چرخه کامل CRUD روی فایل JSON', function () use ($BASE, $ACC, $decosStore) {
    $http = admin_http($BASE, $ACC);
    $key = Fixtures::uniq('deco');
    $res1 = $http->postJson('/admin.php?api=save_deco', ['key' => $key, 'svg' => '<svg></svg>']);
    Assert::jsonOk($res1, 'save_deco معتبر باید موفق باشد');
    Assert::true(isset($decosStore->all()[$key]), 'کلید deco باید در JSON ذخیره شده باشد');

    $res2 = $http->postJson('/admin.php?api=delete_deco', ['key' => $key]);
    Assert::jsonOk($res2, 'delete_deco باید موفق باشد');
    Assert::true(!isset($decosStore->all()[$key]), 'کلید deco باید از JSON حذف شده باشد');
});

Fixtures::deleteToolsByPrefix();

// پاک‌سازی دفاعی: آیکون‌ها/دکوها روی فایل JSON ذخیره می‌شوند نه DB، پس sweep مبتنی
// بر DB در Fixtures آن‌ها را نمی‌بیند — اگر یکی از تست‌های بالا قبل از cleanup خودش
// exception بخورد، کلید zztest_ در فایل می‌ماند؛ این بخش هر بار همه را جارو می‌کند.
$iconsAll = $iconsStore->all();
foreach (array_keys($iconsAll) as $k) if (str_starts_with($k, Fixtures::PREFIX)) unset($iconsAll[$k]);
$iconsStore->save($iconsAll);

$decosAll = $decosStore->all();
foreach (array_keys($decosAll) as $k) if (str_starts_with($k, Fixtures::PREFIX)) unset($decosAll[$k]);
$decosStore->save($decosAll);
