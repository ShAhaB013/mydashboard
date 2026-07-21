<?php
declare(strict_types=1);

if (!isset($cfg)) $cfg = require __DIR__ . '/bootstrap.php';
$BASE = $cfg['test']['base_url'];
$ACC  = $cfg['test']['accounts'];

Assert::group('31_admin_tools');

Assert::test('list_tools → pagination صحیح', function () use ($BASE, $ACC) {
    $http = admin_http($BASE, $ACC);
    $res = $http->get('/admin.php?api=list_tools');
    Assert::jsonOk($res, 'list_tools باید ok:true بدهد');
    Assert::true(isset($res['json']['pagination']['total']), 'باید pagination.total داشته باشد');
});

Assert::test('add بدون title → خطای تمیز', function () use ($BASE, $ACC) {
    $http = admin_http($BASE, $ACC);
    $res = $http->postJson('/admin.php?api=add', ['title' => '', 'path' => '/x']);
    Assert::jsonFail($res, 'title خالی باید رد شود');
});

Assert::test('add با path نامعتبر (javascript:) → رد می‌شود', function () use ($BASE, $ACC) {
    $http = admin_http($BASE, $ACC);
    $res = $http->postJson('/admin.php?api=add', ['title' => Fixtures::uniq('xss'), 'path' => 'javascript:alert(1)']);
    Assert::jsonFail($res, 'path با javascript: باید رد شود');
});

Assert::test('add معتبر → ok + یکپارچگی DB (ردیف واقعا ساخته شد)', function () use ($BASE, $ACC) {
    $http = admin_http($BASE, $ACC);
    $title = Fixtures::uniq('tool');
    $before = (int) DB::run("SELECT COUNT(*) c FROM tools WHERE title LIKE :p", [':p' => Fixtures::PREFIX . '%'])->fetchColumn();
    $res = $http->postJson('/admin.php?api=add', ['title' => $title, 'path' => '/' . $title, 'badge' => 'zztest', 'iconKey' => 'star', 'deco' => 'generic']);
    Assert::jsonOk($res, 'add معتبر باید موفق باشد');
    $after = (int) DB::run("SELECT COUNT(*) c FROM tools WHERE title LIKE :p", [':p' => Fixtures::PREFIX . '%'])->fetchColumn();
    Assert::eq($before + 1, $after, 'دقیقا یک ردیف جدید باید ساخته شود');
});

Assert::test('edit روی id ناموجود → خطای تمیز نه 500', function () use ($BASE, $ACC) {
    $http = admin_http($BASE, $ACC);
    $res = $http->postJson('/admin.php?api=edit', ['id' => 999999999, 'title' => 'x', 'path' => '/x']);
    Assert::true($res['status'] < 500, 'id ناموجود نباید 500 بدهد');
    Assert::jsonFail($res, 'id ناموجود باید ok:false بدهد');
});

Assert::test('edit معتبر → مقادیر DB واقعا آپدیت می‌شوند', function () use ($BASE, $ACC) {
    $http = admin_http($BASE, $ACC);
    $id = Fixtures::createTool();
    $newTitle = Fixtures::uniq('edited');
    $res = $http->postJson('/admin.php?api=edit', ['id' => $id, 'title' => $newTitle, 'path' => '/' . $newTitle, 'badge' => 'zztest']);
    Assert::jsonOk($res, 'edit معتبر باید موفق باشد');
    $row = DB::run('SELECT title FROM tools WHERE id=:id', [':id' => $id])->fetch();
    Assert::eq($newTitle, $row['title'] ?? null, 'عنوان در DB باید آپدیت شده باشد');
});

Assert::test('delete → ردیف واقعا از DB حذف می‌شود', function () use ($BASE, $ACC) {
    $http = admin_http($BASE, $ACC);
    $id = Fixtures::createTool();
    $res = $http->postJson('/admin.php?api=delete', ['id' => $id]);
    Assert::jsonOk($res, 'delete باید موفق باشد');
    $row = DB::run('SELECT id FROM tools WHERE id=:id', [':id' => $id])->fetch();
    Assert::true($row === false, 'ردیف باید از DB حذف شده باشد');
});

Assert::test('reorder → sort_order یکتا و پیوسته برای ids ارسالی می‌شود', function () use ($BASE, $ACC) {
    $http = admin_http($BASE, $ACC);
    $id1 = Fixtures::createTool(['sort_order' => 1]);
    $id2 = Fixtures::createTool(['sort_order' => 2]);
    $id3 = Fixtures::createTool(['sort_order' => 3]);

    // reorderByIds requires the exact, complete list of every id in tools
    // (not just the test subset) — we move the three test tools to the front of the current list.
    $allIds = array_map(fn($r) => (int) $r['id'], DB::run('SELECT id FROM tools ORDER BY sort_order')->fetchAll());
    $rest   = array_values(array_diff($allIds, [$id1, $id2, $id3]));
    $newOrder = array_merge([$id3, $id1, $id2], $rest);

    $res = $http->postJson('/admin.php?api=reorder', ['ids' => $newOrder]);
    Assert::jsonOk($res, 'reorder با لیست کامل باید موفق باشد');
    $rows = DB::run('SELECT id, sort_order FROM tools WHERE id IN (:a,:b,:c) ORDER BY sort_order', [':a' => $id1, ':b' => $id2, ':c' => $id3])->fetchAll();
    $orders = array_map(fn($r) => (int) $r['sort_order'], $rows);
    Assert::eq(count($orders), count(array_unique($orders)), 'sort_order ها باید یکتا باشند');
    Assert::eq((int) $id3, (int) $rows[0]['id'], 'ابزار اول لیست ارسالی باید کمترین sort_order را بگیرد');

    $allOrders = array_map(fn($r) => (int) $r['sort_order'], DB::run('SELECT sort_order FROM tools')->fetchAll());
    Assert::eq(count($allOrders), count(array_unique($allOrders)), 'sort_order کل جدول باید بعد از reorder یکتا و پیوسته باشد (بدون تکرار)');
});

Assert::test('reorder با آرایه خالی → خطا (نه موفقیت خاموش)', function () use ($BASE, $ACC) {
    $http = admin_http($BASE, $ACC);
    $res = $http->postJson('/admin.php?api=reorder', ['ids' => []]);
    Assert::jsonFail($res, 'آرایه خالی باید رد شود');
});

Assert::test('reorder با لیست ناقص (کمتر از کل ابزارها) → رد می‌شود (بدون خراب‌کردن ترتیب موجود)', function () use ($BASE, $ACC) {
    $http = admin_http($BASE, $ACC);
    $id = Fixtures::createTool();
    $res = $http->postJson('/admin.php?api=reorder', ['ids' => [$id]]);
    Assert::jsonFail($res, 'لیست ناقص (فقط یک ابزار از بین همه) باید رد شود');
    DB::run('DELETE FROM tools WHERE id=:id', [':id' => $id]);
});

Fixtures::deleteToolsByPrefix();
