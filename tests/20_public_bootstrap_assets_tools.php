<?php
declare(strict_types=1);

if (!isset($cfg)) $cfg = require __DIR__ . '/bootstrap.php';
$BASE = $cfg['test']['base_url'];

Assert::group('20_public_bootstrap_assets_tools');

Assert::test('bootstrap بدون لاگین → 401', function () use ($BASE) {
    $http = new HttpClient($BASE);
    $res = $http->get('/api.php?action=bootstrap');
    Assert::statusEq($res, 401, 'bootstrap بدون لاگین باید 401 بدهد');
});

Assert::test('bootstrap لاگین‌شده → ساختار صحیح (me/assets/tools/unread)', function () use ($BASE, $cfg) {
    $acc = $cfg['test']['accounts']['user'];
    $http = new HttpClient($BASE);
    $http->loginAs($acc['username'], $acc['password']);
    $res = $http->get('/api.php?action=bootstrap');
    Assert::jsonOk($res, 'bootstrap باید ok:true بدهد');
    foreach (['me', 'assets', 'tools', 'unread'] as $k) {
        Assert::true(isset($res['json'][$k]), "bootstrap باید کلید {$k} داشته باشد");
    }
    Assert::eq(true, $res['json']['me']['logged_in'] ?? null, 'کاربر لاگین‌شده باید logged_in:true باشد');
});

Assert::test('bootstrap → ETag ست می‌شود و 304 روی If-None-Match تکراری', function () use ($BASE, $cfg) {
    $acc = $cfg['test']['accounts']['user'];
    $http = new HttpClient($BASE);
    $http->loginAs($acc['username'], $acc['password']);
    $res1 = $http->get('/api.php?action=bootstrap');
    $etag = $res1['headers']['etag'] ?? null;
    Assert::true($etag !== null, 'ETag header باید ست شود');
    if ($etag === null) return;
    $res2 = $http->get('/api.php?action=bootstrap', ['If-None-Match: ' . $etag]);
    Assert::statusEq($res2, 304, 'درخواست دوم با If-None-Match مطابق باید 304 بدهد');
});

Assert::test('assets بدون لاگین → 401', function () use ($BASE) {
    $http = new HttpClient($BASE);
    $res = $http->get('/api.php?action=assets');
    Assert::statusEq($res, 401, 'assets بدون لاگین باید 401 بدهد');
});

Assert::test('assets لاگین‌شده → ok:true + آرایه icons/decos', function () use ($BASE, $cfg) {
    $acc = $cfg['test']['accounts']['user'];
    $http = new HttpClient($BASE);
    $http->loginAs($acc['username'], $acc['password']);
    $res = $http->get('/api.php?action=assets');
    Assert::jsonOk($res, 'assets باید ok:true بدهد');
    Assert::true(isset($res['json']['icons']) && isset($res['json']['decos']), 'باید icons و decos داشته باشد');
});

Assert::test('tools بدون لاگین → 401', function () use ($BASE) {
    $http = new HttpClient($BASE);
    $res = $http->get('/api.php?action=tools');
    Assert::statusEq($res, 401, 'tools بدون لاگین باید 401 بدهد');
});

Assert::test('tools لاگین‌شده → آرایه ابزارهای در دسترس کاربر', function () use ($BASE, $cfg) {
    $acc = $cfg['test']['accounts']['user'];
    $http = new HttpClient($BASE);
    $http->loginAs($acc['username'], $acc['password']);
    $res = $http->get('/api.php?action=tools');
    Assert::jsonOk($res, 'tools باید ok:true بدهد');
    Assert::true(is_array($res['json']['tools'] ?? null), 'tools باید آرایه باشد');
});

Assert::test('action نامعتبر → 400', function () use ($BASE) {
    $http = new HttpClient($BASE);
    $res = $http->get('/api.php?action=' . Fixtures::uniq('bogus'));
    Assert::statusEq($res, 400, 'action ناشناخته باید 400 بدهد');
});

Assert::test('bootstrap با متد POST بدون بدنه هم پاسخ می‌دهد (بدون method-check صریح — یافته)', function () use ($BASE, $cfg) {
    $acc = $cfg['test']['accounts']['user'];
    $http = new HttpClient($BASE);
    $http->loginAs($acc['username'], $acc['password']);
    $res = $http->postJson('/api.php?action=bootstrap', [], [], false);
    if ($res['status'] === 200) {
        Assert::warn('bootstrap متد POST را هم می‌پذیرد (فاقد method allowlist صریح) — endpoint فقط-خواندنی است پس ریسک کم است اما به‌عنوان یافته ثبت شد');
    } else {
        Assert::true(true, 'bootstrap متد POST را رد کرد');
    }
});
