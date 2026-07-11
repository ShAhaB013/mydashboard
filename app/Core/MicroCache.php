<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// MicroCache — میکروکش فایلی پاسخ‌های مشترک (شاخه مهمان API)
//
// چرا: پاسخ‌های مهمان (bootstrap/tools/notifications) بین همه بازدیدکنندگان
// بایت‌به‌بایت یکسان‌اند ولی بدون کش سرور، هر درخواست مستقلا کوئری کامل +
// serialize را اجرا می‌کند. این کلاس در ترافیک بالا N محاسبه همزمان را به ۱
// تبدیل می‌کند. فقط برای پاسخ‌های بدون دادهٔ per-user استفاده شود.
//
// سازوکار ضد-stampede:
//   • جیتر TTL: انقضای واقعی = ttl + random(0..ttl/5) — انقضای کلیدها هم‌زمان نمی‌شود
//   • single-flight: هنگام انقضا فقط درخواستی که قفل را می‌گیرد rebuild می‌کند؛
//     بقیه بلافاصله نسخهٔ stale موجود را می‌گیرند (stale-while-revalidate)
//   • قفل یتیم (کرش وسط rebuild) بعد از LOCK_TIMEOUT ثانیه بازپس‌گیری می‌شود
//
// ذخیره‌سازی: sys_get_temp_dir()/das-cache/{md5(key)}.cache
//   خط اول = timestamp انقضا، ادامه = بدنهٔ پاسخ (نوشتن اتمیک tmp + rename،
//   همان الگوی JsonStore::save)
// ═══════════════════════════════════════════════════════════

class MicroCache
{
    /** مهلت بازپس‌گیری قفل یتیم‌شده (ثانیه) */
    private const LOCK_TIMEOUT = 10;

    /**
     * بدنهٔ کش‌شده را برمی‌گرداند؛ در صورت انقضا فقط یک درخواست بازسازی می‌کند
     * و بقیه نسخهٔ stale را می‌گیرند. در کش سرد (هیچ نسخه‌ای موجود نیست) بدون
     * قفل مستقیم build می‌شود.
     *
     * @param callable():string $builder سازندهٔ بدنهٔ تازه (فقط در صورت نیاز صدا می‌شود)
     */
    public static function remember(string $key, int $ttl, callable $builder): string
    {
        $file  = self::path($key);
        $now   = time();
        $stale = null;

        $raw = @file_get_contents($file);
        if ($raw !== false) {
            $nl = strpos($raw, "\n");
            if ($nl !== false) {
                $expires = (int) substr($raw, 0, $nl);
                $body    = substr($raw, $nl + 1);
                if ($expires > $now) {
                    return $body;          // تازه — پرتکرارترین مسیر
                }
                $stale = $body;            // منقضی ولی قابل سرو تا rebuild تمام شود
            }
        }

        // منقضی یا ناموجود — فقط یک درخواست (برندهٔ قفل) بازسازی می‌کند
        if (self::acquireLock($file)) {
            try {
                $body = $builder();
                self::store($file, $body, $ttl);
                return $body;
            } finally {
                @unlink($file . '.lock');
            }
        }

        // قفل دست درخواست دیگری است: اگر نسخهٔ stale داریم همان را فورا سرو کن
        if ($stale !== null) {
            return $stale;
        }

        // کش سرد و قفل هم آزاد نشد — محاسبه مستقیم بدون نوشتن (نادر: فقط
        // چند درخواست اول پس از پاک‌شدن کامل کش)
        return $builder();
    }

    /** حذف فوری یک کلید (پس از نوشتن ادمین، تا تغییر بلافاصله دیده شود) */
    public static function forget(string $key): void
    {
        @unlink(self::path($key));
    }

    // ── internals ────────────────────────────────────────────

    /** نوشتن اتمیک «expiry\nbody» با انقضای جیتردار */
    private static function store(string $file, string $body, int $ttl): void
    {
        $jitter  = $ttl >= 5 ? random_int(0, intdiv($ttl, 5)) : 0;
        $expires = time() + $ttl + $jitter;
        $tmp     = $file . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $expires . "\n" . $body, LOCK_EX) !== false) {
            @rename($tmp, $file);
        }
    }

    /** تصاحب قفل rebuild (fopen حالت x اتمیک است)؛ قفل یتیم را بازپس می‌گیرد */
    private static function acquireLock(string $file): bool
    {
        $lock = $file . '.lock';
        $fh   = @fopen($lock, 'x');
        if ($fh !== false) {
            fclose($fh);
            return true;
        }
        // قفل موجود: اگر از LOCK_TIMEOUT کهنه‌تر است (کرش وسط rebuild) بازپس‌گیری
        $mtime = @filemtime($lock);
        if ($mtime !== false && (time() - $mtime) > self::LOCK_TIMEOUT) {
            @unlink($lock);
            $fh = @fopen($lock, 'x');
            if ($fh !== false) {
                fclose($fh);
                return true;
            }
        }
        return false;
    }

    private static function path(string $key): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'das-cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        return $dir . DIRECTORY_SEPARATOR . md5($key) . '.cache';
    }
}
