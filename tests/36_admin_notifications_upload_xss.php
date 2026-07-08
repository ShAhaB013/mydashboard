<?php
declare(strict_types=1);

if (!isset($cfg)) $cfg = require __DIR__ . '/bootstrap.php';
$BASE = $cfg['test']['base_url'];
$ACC  = $cfg['test']['accounts'];

Assert::group('36_admin_notifications_upload_xss');

$tmpDir = sys_get_temp_dir() . '/dastest_uploads';
if (!is_dir($tmpDir)) mkdir($tmpDir, 0755, true);

// PNG معتبر ۱×۱ پیکسل (base64)
$validPngB64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
file_put_contents($tmpDir . '/valid.png', base64_decode($validPngB64));

$phpPayload = '<?php echo "pwned"; ?>';
file_put_contents($tmpDir . '/shell.php', $phpPayload);

$svgPayload = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
file_put_contents($tmpDir . '/evil.svg', $svgPayload);

// polyglot: هدر GIF89a + کد PHP در ادامه
file_put_contents($tmpDir . '/polyglot.gif', "GIF89a" . $phpPayload);

$uploadedPaths = [];

function admin_upload_dir(): string
{
    return dirname(__DIR__) . '/uploads/notifications';
}

Assert::test('آپلود PNG معتبر → موفق و پسوند مشتق از MIME واقعی است', function () use ($BASE, $ACC, $tmpDir, &$uploadedPaths) {
    $http = admin_http($BASE, $ACC);
    $res = $http->uploadFile('/admin.php?api=upload_notification_image', 'image', $tmpDir . '/valid.png', 'image/png', 'valid.png');
    Assert::true(($res['json']['ok'] ?? false) === true, 'آپلود PNG معتبر باید موفق باشد', ['json' => $res['json']]);
    if (isset($res['json']['image_path'])) $uploadedPaths[] = $res['json']['image_path'];
});

Assert::test('عکس PNG با پسوند جعلی .php (اما محتوای واقعی PNG) → پسوند ذخیره‌شده هرگز php نیست', function () use ($BASE, $ACC, $tmpDir, &$uploadedPaths) {
    $http = admin_http($BASE, $ACC);
    // محتوای واقعی PNG است اما نام فایل .php — سرور باید پسوند را از MIME واقعی مشتق کند نه از نام فایل
    $res = $http->uploadFile('/admin.php?api=upload_notification_image', 'image', $tmpDir . '/valid.png', 'image/png', 'disguise.php');
    if (($res['json']['ok'] ?? false) === true) {
        $path = (string) ($res['json']['image_path'] ?? '');
        Assert::notContains($path, '.php', 'مسیر ذخیره‌شده هرگز نباید پسوند .php داشته باشد');
        $uploadedPaths[] = $path;
    } else {
        Assert::true(true, 'رد آپلود هم قابل قبول است (نتیجه امن)');
    }
});

Assert::test('آپلود فایل PHP واقعی با پسوند/محتوای غیرتصویری → رد می‌شود (finfo واقعی)', function () use ($BASE, $ACC, $tmpDir) {
    $http = admin_http($BASE, $ACC);
    $res = $http->uploadFile('/admin.php?api=upload_notification_image', 'image', $tmpDir . '/shell.php', 'image/png', 'shell.png');
    Assert::true(($res['json']['ok'] ?? true) === false, 'فایل PHP واقعی (حتی با MIME/نام جعلی) باید بر اساس محتوای واقعی رد شود', ['json' => $res['json']]);
});

Assert::test('آپلود SVG با اسکریپت → رد می‌شود (image/svg+xml در ALLOWED_MIMES نیست)', function () use ($BASE, $ACC, $tmpDir) {
    $http = admin_http($BASE, $ACC);
    $res = $http->uploadFile('/admin.php?api=upload_notification_image', 'image', $tmpDir . '/evil.svg', 'image/svg+xml', 'evil.svg');
    Assert::true(($res['json']['ok'] ?? true) === false, 'SVG باید رد شود');
});

Assert::test('آپلود polyglot (GIF header + PHP payload) → رد می‌شود یا بدون اجرای کد ذخیره می‌شود', function () use ($BASE, $ACC, $tmpDir, &$uploadedPaths) {
    $http = admin_http($BASE, $ACC);
    $res = $http->uploadFile('/admin.php?api=upload_notification_image', 'image', $tmpDir . '/polyglot.gif', 'image/gif', 'polyglot.gif');
    if (($res['json']['ok'] ?? false) === true) {
        $path = (string) ($res['json']['image_path'] ?? '');
        Assert::notContains($path, '.php', 'حتی اگر polyglot به‌عنوان GIF پذیرفته شود، پسوند نباید php باشد');
        $uploadedPaths[] = $path;
    } else {
        Assert::true(true, 'رد شدن polyglot هم پذیرفتنی است');
    }
});

Assert::test('آپلود بدون فایل → پیام خطای تمیز نه 500', function () use ($BASE, $ACC) {
    $http = admin_http($BASE, $ACC);
    $res = $http->postJson('/admin.php?api=upload_notification_image', []);
    Assert::true($res['status'] < 500, 'درخواست بدون فایل نباید 500 بدهد');
    Assert::jsonFail($res, 'درخواست بدون فایل باید ok:false بدهد');
});

// ── پاک‌سازی فایل‌های واقعی آپلودشده روی دیسک ──
foreach ($uploadedPaths as $p) {
    if ($p === '') continue;
    $full = dirname(__DIR__) . $p;
    if (is_file($full)) @unlink($full);
    // نسخه thumbnail احتمالی
    $thumb = dirname($full) . '/thumbs/' . basename($full);
    if (is_file($thumb)) @unlink($thumb);
}
