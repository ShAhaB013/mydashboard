<?php
declare(strict_types=1);

if (!isset($cfg)) $cfg = require __DIR__ . '/bootstrap.php';
$BASE = $cfg['test']['base_url'];
$ACC  = $cfg['test']['accounts'];
$P95_THRESHOLD = $cfg['test']['perf']['p95_ms'] ?? 500;
$MAX_THRESHOLD = $cfg['test']['perf']['max_ms'] ?? 2000;

Assert::group('50_performance');

function percentile(array $values, float $p): float
{
    sort($values);
    $n = count($values);
    if ($n === 0) return 0.0;
    $idx = (int) ceil($p / 100 * $n) - 1;
    return $values[max(0, min($n - 1, $idx))];
}

function burstTiming(string $BASE, string $path, int $n, array $headers = []): array
{
    $http = new HttpClient($BASE);
    $times = [];
    for ($i = 0; $i < $n; $i++) {
        $res = $http->get($path, $headers);
        $times[] = $res['time_ms'];
    }
    return $times;
}

foreach (['/api.php?action=bootstrap' => 'bootstrap', '/api.php?action=tools' => 'tools', '/api.php?action=notifications' => 'notifications'] as $path => $label) {
    Assert::test("burst 30 درخواست sequential روی {$label} → p95 زیر آستانه نرم", function () use ($BASE, $path, $label, $P95_THRESHOLD, $MAX_THRESHOLD) {
        $times = burstTiming($BASE, $path, 30);
        $p95 = percentile($times, 95);
        $max = max($times);
        if ($p95 > $P95_THRESHOLD) {
            Assert::warn("p95 برای {$label} = " . round($p95, 1) . "ms (آستانه {$P95_THRESHOLD}ms) — کند است اما FAIL سخت نیست");
        } else {
            Assert::true(true, "p95 برای {$label} در آستانه است (" . round($p95, 1) . "ms)");
        }
        Assert::true($max < $MAX_THRESHOLD * 3, "حداکثر زمان پاسخ {$label} نباید فاجعه‌بار باشد", ['max_ms' => $max]);
    });
}

Assert::test('list_tools ادمین: تعداد کوئری DB با افزایش نتایج به‌صورت خطی رشد نمی‌کند (N+1 smell)', function () use ($BASE, $ACC) {
    // create 5 test rows to compare against the baseline
    $ids = [];
    for ($i = 0; $i < 5; $i++) $ids[] = Fixtures::createTool();

    $http = admin_http($BASE, $ACC);

    $before = (int) DB::run("SHOW SESSION STATUS LIKE 'Questions'")->fetch()['Value'];
    $http->get('/admin.php?api=list_tools&per_page=5');
    $after5 = (int) DB::run("SHOW SESSION STATUS LIKE 'Questions'")->fetch()['Value'];
    $q5 = $after5 - $before;

    for ($i = 0; $i < 20; $i++) $ids[] = Fixtures::createTool();

    $before2 = (int) DB::run("SHOW SESSION STATUS LIKE 'Questions'")->fetch()['Value'];
    $http->get('/admin.php?api=list_tools&per_page=25');
    $after25 = (int) DB::run("SHOW SESSION STATUS LIKE 'Questions'")->fetch()['Value'];
    $q25 = $after25 - $before2;

    // these queries themselves also consume DB; we only flag disproportionate growth (linear with row count)
    if ($q25 > $q5 + 15) {
        Assert::warn("تعداد کوئری با ۵ برابر شدن نتایج به‌طرز نامتناسبی رشد کرد (q5={$q5}, q25={$q25}) — احتمال N+1");
    } else {
        Assert::true(true, "رشد تعداد کوئری متناسب است (q5={$q5}, q25={$q25})");
    }

    foreach ($ids as $id) DB::run('DELETE FROM tools WHERE id=:id', [':id' => $id]);
});

Assert::test('ETag: تغییر داده باعث تغییر ETag و بازگشت 200 تازه می‌شود', function () use ($BASE) {
    $http = new HttpClient($BASE);
    $res1 = $http->get('/api.php?action=tools');
    $etag1 = $res1['headers']['etag'] ?? null;
    if ($etag1 === null) { Assert::warn('ETag روی tools ست نشده (شاید کاربر لاگین بود) — رد شد'); return; }

    $id = Fixtures::createTool(['is_public' => 1]);
    $res2 = $http->get('/api.php?action=tools', ['If-None-Match: ' . $etag1]);
    Assert::statusEq($res2, 200, 'بعد از تغییر داده، If-None-Match قدیمی نباید 304 بگیرد');
    DB::run('DELETE FROM tools WHERE id=:id', [':id' => $id]);
});

Fixtures::deleteToolsByPrefix();
