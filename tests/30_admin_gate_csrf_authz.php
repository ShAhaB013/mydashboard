<?php
declare(strict_types=1);

if (!isset($cfg)) $cfg = require __DIR__ . '/bootstrap.php';
$BASE = $cfg['test']['base_url'];
$ACC  = $cfg['test']['accounts'];

Assert::group('30_admin_gate_csrf_authz');

Assert::test('admin.php?api=list_tools بدون لاگین → 401', function () use ($BASE) {
    $http = new HttpClient($BASE);
    $res = $http->get('/admin.php?api=list_tools');
    Assert::statusEq($res, 401, 'مهمان باید 401 بگیرد');
});

Assert::test('admin.php?api=list_tools با کاربر عادی (نه ادمین) → 403', function () use ($BASE, $ACC) {
    $http = new HttpClient($BASE);
    $http->loginAs($ACC['user']['username'], $ACC['user']['password']);
    $res = $http->get('/admin.php?api=list_tools', ['X-CSRF-Token: ' . ($http->csrfToken() ?? '')]);
    Assert::statusEq($res, 403, 'کاربر عادی باید 403 بگیرد حتی با CSRF صحیح خودش');
});

Assert::test('admin.php?api=list_tools ادمین بدون هدر CSRF (حتی GET) → 403', function () use ($BASE, $ACC) {
    $http = new HttpClient($BASE);
    $http->loginAs($ACC['admin']['username'], $ACC['admin']['password'], '/admin.php');
    $http->setCsrfToken(null);
    $res = $http->get('/admin.php?api=list_tools');
    Assert::statusEq($res, 403, 'حتی GET خواندنی ادمین بدون هدر CSRF باید 403 بدهد (سخت‌گیرتر از api.php)');
});

Assert::test('admin.php?api=list_tools ادمین با توکن نامعتبر/جعلی → 403', function () use ($BASE, $ACC) {
    $http = new HttpClient($BASE);
    $http->loginAs($ACC['admin']['username'], $ACC['admin']['password'], '/admin.php');
    $http->setCsrfToken(str_repeat('a', 64));
    $res = $http->get('/admin.php?api=list_tools');
    Assert::statusEq($res, 403, 'توکن جعلی هم‌طول باید رد شود (hash_equals)');
});

Assert::test('admin.php?api=list_tools ادمین با CSRF صحیح → 200', function () use ($BASE, $ACC) {
    $http = new HttpClient($BASE);
    $http->loginAs($ACC['admin']['username'], $ACC['admin']['password'], '/admin.php');
    $res = $http->get('/admin.php?api=list_tools');
    Assert::jsonOk($res, 'ادمین با CSRF صحیح باید بتواند list_tools بگیرد');
});

Assert::test('توکن CSRF نشست دیگر (ادمین دوم) قابل replay روی این نشست نیست', function () use ($BASE, $ACC) {
    // نشست ۱: کاربر عادی لاگین می‌کند و CSRF خودش را می‌گیرد
    $httpUser = new HttpClient($BASE);
    $httpUser->loginAs($ACC['user']['username'], $ACC['user']['password']);
    $userToken = $httpUser->csrfToken();

    // نشست ۲: ادمین لاگین می‌کند اما توکن نشست کاربر عادی را روی خودش می‌گذارد
    $httpAdmin = new HttpClient($BASE);
    $httpAdmin->loginAs($ACC['admin']['username'], $ACC['admin']['password'], '/admin.php');
    $httpAdmin->setCsrfToken($userToken);
    $res = $httpAdmin->get('/admin.php?api=list_tools');
    Assert::statusEq($res, 403, 'توکن CSRF نشست دیگر نباید پذیرفته شود');
});

Assert::test('bypass متد HTTP: GET با کوئری روی action جهش‌زا (add) → بررسی می‌شود', function () use ($BASE, $ACC) {
    $http = new HttpClient($BASE);
    $http->loginAs($ACC['admin']['username'], $ACC['admin']['password'], '/admin.php');
    $title = Fixtures::uniq('methodbypass');
    $token = $http->csrfToken() ?? '';
    $res = $http->get('/admin.php?api=add&title=' . urlencode($title) . '&path=' . urlencode('/' . $title), ['X-CSRF-Token: ' . $token]);
    // یافته شناخته‌شده: Request::input فقط از php://input (JSON body) می‌خواند، نه از $_GET.
    // پس با GET و فقط querystring، بدنه خالی است و 'عنوان الزامی است' برمی‌گردد — یعنی از این مسیر مشخص سوءاستفاده نمی‌شود.
    $created = Fixtures::findToolByTitle($title);
    Assert::true($created === null, 'GET با querystring نباید بتواند ابزار جدید بسازد (Request فقط JSON body را می‌خواند)');
    if ($created !== null) DB::run('DELETE FROM tools WHERE id=:id', [':id' => $created['id']]);
});

Assert::test('تازگی مجوز ادمین: تنزل نقش در DB حین نشست فعال → درخواست بعدی 403 می‌شود', function () use ($BASE, $ACC) {
    $http = new HttpClient($BASE);
    $http->loginAs($ACC['admin']['username'], $ACC['admin']['password'], '/admin.php');
    $res1 = $http->get('/admin.php?api=list_tools');
    Assert::jsonOk($res1, 'ابتدا دسترسی ادمین باید کار کند');

    // دستکاری مستقیم DB (bypass اپلیکیشن) — تنزل نقش به user
    DB::run("UPDATE users SET role='user' WHERE username=:u", [':u' => $ACC['admin']['username']]);
    try {
        $res2 = $http->get('/admin.php?api=list_tools');
        Assert::statusEq($res2, 403, 'بعد از تنزل نقش در DB، همان کوکی نشست باید بلافاصله 403 بگیرد (چک تازه از DB، نه session cache)');
    } finally {
        // بازگرداندن نقش ادمین برای بقیه‌ی تست‌ها
        DB::run("UPDATE users SET role='admin' WHERE username=:u", [':u' => $ACC['admin']['username']]);
    }
});
