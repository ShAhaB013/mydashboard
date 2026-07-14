<?php
declare(strict_types=1);

if (!isset($cfg)) $cfg = require __DIR__ . '/bootstrap.php';
$BASE = $cfg['test']['base_url'];
$ACC  = $cfg['test']['accounts'];

Assert::group('35_admin_notifications_crud');

Assert::test('create_notification بدون title → رد می‌شود', function () use ($BASE, $ACC) {
    $http = admin_http($BASE, $ACC);
    $res = $http->postJson('/admin.php?api=create_notification', ['title' => '', 'body' => 'x']);
    Assert::jsonFail($res, 'title خالی باید رد شود');
});

Assert::test('create_notification معتبر → ردیف واقعا در DB ساخته می‌شود', function () use ($BASE, $ACC) {
    $http = admin_http($BASE, $ACC);
    $title = Fixtures::uniq('notif');
    $res = $http->postJson('/admin.php?api=create_notification', ['title' => $title, 'body' => 'متن تست', 'is_public' => 1, 'target_all_users' => 1]);
    Assert::jsonOk($res, 'create_notification معتبر باید موفق باشد');
    $row = DB::run('SELECT id FROM notifications WHERE title=:t', [':t' => $title])->fetch();
    Assert::true($row !== false, 'اعلان باید در DB ساخته شده باشد');
});

Assert::test('update_notification روی id ناموجود → خطای تمیز', function () use ($BASE, $ACC) {
    $http = admin_http($BASE, $ACC);
    $res = $http->postJson('/admin.php?api=update_notification', ['id' => 999999999, 'title' => 'x']);
    Assert::true($res['status'] < 500, 'id ناموجود نباید 500 بدهد');
    Assert::jsonFail($res, 'id ناموجود باید ok:false بدهد');
});

Assert::test('delete_notification → ردیف از DB حذف می‌شود', function () use ($BASE, $ACC) {
    $http = admin_http($BASE, $ACC);
    $id = Fixtures::createNotification();
    $res = $http->postJson('/admin.php?api=delete_notification', ['id' => $id]);
    Assert::jsonOk($res, 'delete_notification باید موفق باشد');
    $row = DB::run('SELECT id FROM notifications WHERE id=:id', [':id' => $id])->fetch();
    Assert::true($row === false, 'ردیف باید حذف شده باشد');
});

Assert::test('list_notifications: FULLTEXT/LIKE boolean-mode با کاراکترهای خاص → بدون خطای 500', function () use ($BASE, $ACC) {
    $http = admin_http($BASE, $ACC);
    $payloads = ['+', '-', '*', '"', '""', '"unterminated', '+-*"()~<>:\\@', 'ab', 'abcde'];
    foreach ($payloads as $q) {
        $res = $http->get('/admin.php?api=list_notifications&search=' . urlencode($q));
        Assert::true($res['status'] < 500, "جستجوی «{$q}» نباید 500 بدهد", ['status' => $res['status']]);
        Assert::jsonOk($res, "جستجوی «{$q}» باید ok:true بدهد (نه خطای SQL)");
    }
});

Assert::test('list_notifications: pagination لبه‌ها (page=0/-1/999999, per_page=0/1000)', function () use ($BASE, $ACC) {
    $http = admin_http($BASE, $ACC);
    foreach ([0, -1, 999999] as $page) {
        $res = $http->get('/admin.php?api=list_notifications&page=' . $page);
        Assert::true($res['status'] < 500, "page={$page} نباید 500 بدهد");
        Assert::jsonOk($res, "page={$page} باید همچنان ok:true بدهد (کلمپ‌شده)");
        Assert::true(($res['json']['pagination']['page'] ?? 0) >= 1, "page نهایی باید >=1 کلمپ شده باشد");
    }
    $resBig = $http->get('/admin.php?api=list_notifications&per_page=1000');
    Assert::true(($resBig['json']['pagination']['per_page'] ?? 0) <= 50, 'per_page باید حداکثر ۵۰ کلمپ شود');
});

Assert::test('XSS: sanitizeBody یک payload بدخیم را قبل از ذخیره پاک می‌کند', function () use ($BASE, $ACC) {
    $http = admin_http($BASE, $ACC);
    // sanitizeBody() disallowed tags را با متن داخلی‌شان جایگزین می‌کند، نه اینکه کامل حذفشان کند —
    // پس payloadهایی که هیچ متن داخلی ندارند (مثل <img>/<svg> خودبسته) پس از پاک‌سازی به رشته‌ی
    // خالی می‌رسند و به‌درستی با خطای "متن اعلان الزامی است" رد می‌شوند؛ این رفتار امن و مورد انتظار
    // است، نه یک باگ. بقیه‌ی payloadها متن داخلی دارند پس باید پاک‌سازی‌شده ذخیره شوند.
    $payloads = [
        ['<img src=x onerror=alert(1)>', false],
        ['<a href="javascript:alert(1)">link</a>', true],
        ['<div style="background:url(javascript:alert(1))">x</div>', true],
        ['<div style="width:expression(alert(1))">x</div>', true],
        ['<svg><script>alert(1)</script></svg>', true],
        ['<svg onload=alert(1)>', false],
        ['<IMG SRC=x OnErRor=alert(1)>', false],
    ];
    foreach ($payloads as $i => [$payload, $expectSaved]) {
        $title = Fixtures::uniq('xss' . $i);
        $res = $http->postJson('/admin.php?api=create_notification', ['title' => $title, 'body' => $payload, 'is_public' => 1, 'target_all_users' => 1]);

        if (!$expectSaved) {
            Assert::jsonFail($res, "payload شماره {$i}: بعد از پاک‌سازی خالی می‌شود، پس باید رد شود (نه ساخته شود)");
            Assert::eq('body', $res['json']['field'] ?? null, "payload شماره {$i}: خطا باید روی فیلد body باشد");
            continue;
        }

        Assert::jsonOk($res, "ایجاد اعلان با payload شماره {$i} نباید خطا بدهد (فقط باید پاک شود)");
        $row = DB::run('SELECT body FROM notifications WHERE title=:t', [':t' => $title])->fetch();
        $stored = (string) ($row['body'] ?? '');
        foreach (['onerror=', 'onload=', 'javascript:', 'expression(', '<script'] as $bad) {
            Assert::notContains(strtolower($stored), strtolower($bad), "payload {$i}: «{$bad}» نباید در body ذخیره‌شده باقی بماند");
        }
    }
});

Fixtures::deleteNotificationsByPrefix();
