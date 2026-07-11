<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// 23_guest_microcache — میکروکش ضد-stampede پاسخ‌های مهمان
//   • hit تازه بدون اجرای builder
//   • جیتر TTL در بازه [ttl, ttl*1.2]
//   • stale-serving وقتی قفل rebuild دست درخواست دیگری است
//   • forget → بازسازی فوری
//   • سطح HTTP: اعلان/ابزار جدید بلافاصله (پس از invalidate) در فید مهمان
// ═══════════════════════════════════════════════════════════

if (!isset($cfg)) $cfg = require __DIR__ . '/bootstrap.php';
$BASE = $cfg['test']['base_url'];

Assert::group('23_guest_microcache');

$cacheFileOf = function (string $key): string {
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'das-cache' . DIRECTORY_SEPARATOR . md5($key) . '.cache';
};

Assert::test('remember → بار دوم از کش (builder فقط یک‌بار اجرا می‌شود)', function () {
    $key   = 'zztest-mc-' . bin2hex(random_bytes(4));
    $calls = 0;
    $builder = function () use (&$calls): string { $calls++; return 'body-' . $calls; };

    $first  = MicroCache::remember($key, 30, $builder);
    $second = MicroCache::remember($key, 30, $builder);

    Assert::eq('body-1', $first, 'بار اول باید خروجی builder باشد');
    Assert::eq('body-1', $second, 'بار دوم باید همان بدنه کش‌شده باشد');
    Assert::eq(1, $calls, 'builder نباید بار دوم اجرا شود');
    MicroCache::forget($key);
});

Assert::test('جیتر TTL → انقضا در بازه [ttl, ttl*1.2]', function () use ($cacheFileOf) {
    $key = 'zztest-mc-' . bin2hex(random_bytes(4));
    $ttl = 100;
    $before = time();
    MicroCache::remember($key, $ttl, fn(): string => 'x');

    $raw = file_get_contents($cacheFileOf($key));
    Assert::true($raw !== false, 'فایل کش باید ساخته شده باشد');
    $expires = (int) substr((string) $raw, 0, (int) strpos((string) $raw, "\n"));

    Assert::true($expires >= $before + $ttl, 'انقضا نباید کمتر از ttl باشد');
    Assert::true($expires <= time() + $ttl + intdiv($ttl, 5), 'انقضا نباید بیش از ttl*1.2 باشد');
    MicroCache::forget($key);
});

Assert::test('stale-serving → با قفل گرفته‌شده، نسخه منقضی فورا سرو می‌شود', function () use ($cacheFileOf) {
    $key  = 'zztest-mc-' . bin2hex(random_bytes(4));
    $file = $cacheFileOf($key);

    // نسخه منقضی + قفل rebuild دست «درخواست دیگر»
    file_put_contents($file, (time() - 5) . "\nstale-body");
    file_put_contents($file . '.lock', '');

    $calls = 0;
    $out = MicroCache::remember($key, 30, function () use (&$calls): string { $calls++; return 'fresh'; });

    Assert::eq('stale-body', $out, 'باید نسخه stale سرو شود، نه انتظار برای rebuild');
    Assert::eq(0, $calls, 'builder نباید اجرا شود وقتی قفل دست دیگری است');

    @unlink($file . '.lock');
    MicroCache::forget($key);
});

Assert::test('انقضا بدون قفل → همان درخواست rebuild می‌کند', function () use ($cacheFileOf) {
    $key  = 'zztest-mc-' . bin2hex(random_bytes(4));
    $file = $cacheFileOf($key);
    file_put_contents($file, (time() - 5) . "\nstale-body");

    $out = MicroCache::remember($key, 30, fn(): string => 'fresh');
    Assert::eq('fresh', $out, 'بدون رقیب، باید بدنه تازه ساخته و برگردانده شود');

    $out2 = MicroCache::remember($key, 30, fn(): string => 'should-not-run');
    Assert::eq('fresh', $out2, 'بدنه تازه باید در کش نشسته باشد');
    MicroCache::forget($key);
});

Assert::test('forget → درخواست بعدی دوباره build می‌کند', function () {
    $key = 'zztest-mc-' . bin2hex(random_bytes(4));
    MicroCache::remember($key, 30, fn(): string => 'v1');
    MicroCache::forget($key);
    $out = MicroCache::remember($key, 30, fn(): string => 'v2');
    Assert::eq('v2', $out, 'بعد از forget باید بدنه جدید ساخته شود');
    MicroCache::forget($key);
});

Assert::test('HTTP مهمان: tools کش‌شده + ETag/304 + انعکاس فوری تغییر', function () use ($BASE) {
    $http = new HttpClient($BASE);

    $res1 = $http->get('/api.php?action=tools');
    Assert::jsonOk($res1, 'tools مهمان باید ok:true بدهد');
    $cc = $res1['headers']['cache-control'] ?? '';
    Assert::true(strpos($cc, 'stale-while-revalidate') !== false, 'Cache-Control مهمان باید stale-while-revalidate داشته باشد');

    $etag = $res1['headers']['etag'] ?? null;
    Assert::true($etag !== null, 'ETag باید ست شود');
    if ($etag !== null) {
        $res304 = $http->get('/api.php?action=tools', ['If-None-Match: ' . $etag]);
        Assert::statusEq($res304, 304, 'با If-None-Match مطابق باید 304 بدهد');
    }

    // ابزار عمومی جدید (fixture کش را invalidate می‌کند) باید بلافاصله دیده شود
    $title  = Fixtures::uniq('mc_tool');
    $toolId = Fixtures::createTool(['title' => $title, 'is_public' => 1]);
    $res2   = $http->get('/api.php?action=tools');
    $titles = array_map(fn($t) => $t['title'] ?? '', $res2['json']['tools'] ?? []);
    Assert::true(in_array($title, $titles, true), 'ابزار عمومی جدید باید بدون انتظار TTL در پاسخ مهمان باشد');

    DB::run('DELETE FROM tools WHERE id = :id', [':id' => $toolId]);
    MicroCache::forget('tools-guest');
    MicroCache::forget('boot-guest');
});

Assert::test('HTTP لاگین‌شده: unread_count حالا me را هم حمل می‌کند', function () use ($BASE, $cfg) {
    $acc  = $cfg['test']['accounts']['user'];
    $http = new HttpClient($BASE);
    $http->loginAs($acc['username'], $acc['password']);
    $res = $http->get('/api.php?action=unread_count');
    Assert::jsonOk($res, 'unread_count باید ok:true بدهد');
    Assert::eq(true, $res['json']['logged_in'] ?? null, 'logged_in باید true باشد');
    Assert::true(isset($res['json']['me']['username']), 'پاسخ باید فیلدهای هویتی me را داشته باشد');
    Assert::eq($acc['username'], $res['json']['me']['username'] ?? null, 'username داخل me باید با کاربر لاگین‌شده یکی باشد');
});

Assert::test('HTTP مهمان: unread_count بدون me (نشت اطلاعات نداریم)', function () use ($BASE) {
    $http = new HttpClient($BASE);
    $res = $http->get('/api.php?action=unread_count');
    Assert::jsonOk($res, 'unread_count مهمان باید ok:true بدهد');
    Assert::true(!isset($res['json']['me']), 'پاسخ مهمان نباید فیلد me داشته باشد');
});
