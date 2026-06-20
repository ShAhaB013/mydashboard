<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// SettingsModel — تنظیمات برنامه به‌صورت key/value در جدول app_settings
// (SMTP سرور ایمیل + زمان‌بندی کد تایید/ارسال مجدد)
// ═══════════════════════════════════════════════════════════

class SettingsModel
{
    /** کلیدهای مجاز + مقدار پیش‌فرض (تنها همین کلیدها ذخیره می‌شوند) */
    public const DEFAULTS = [
        'smtp_enabled'    => '0',
        'smtp_host'       => '',
        'smtp_port'       => '587',
        'smtp_secure'     => 'tls',   // tls | ssl | none
        'smtp_user'       => '',
        'smtp_pass'       => '',
        'smtp_from_email' => '',
        'smtp_from_name'  => 'داشبورد ابزارها',
        'resend_cooldown' => '30',    // ثانیه — فاصله مجاز برای ارسال مجدد کد
        'code_ttl'        => '600',   // ثانیه — مدت اعتبار کد تایید
    ];

    /** کش درون‌درخواستی تا چند بار به DB نزنیم */
    private static ?array $cache = null;

    /** همه تنظیمات به‌صورت map (با اعمال پیش‌فرض‌ها برای کلیدهای غایب) */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $out = self::DEFAULTS;
        try {
            $rows = DB::run('SELECT skey, svalue FROM app_settings')->fetchAll();
            foreach ($rows as $r) {
                if (array_key_exists($r['skey'], self::DEFAULTS)) {
                    $out[$r['skey']] = (string) ($r['svalue'] ?? '');
                }
            }
        } catch (\Throwable $e) {
            // جدول هنوز ساخته نشده → پیش‌فرض‌ها
        }
        return self::$cache = $out;
    }

    /** خواندن یک کلید */
    public static function get(string $key, ?string $default = null): ?string
    {
        $all = self::all();
        return $all[$key] ?? $default ?? (self::DEFAULTS[$key] ?? null);
    }

    /** خواندن یک کلید به‌صورت عدد صحیح با حداقل/حداکثر */
    public static function getInt(string $key, int $min, int $max, int $fallback): int
    {
        $v = (int) self::get($key, (string) $fallback);
        if ($v < $min) return $min;
        if ($v > $max) return $max;
        return $v;
    }

    /** ذخیره گروهی — فقط کلیدهای مجاز اعمال می‌شوند */
    public static function setMany(array $kv): void
    {
        foreach ($kv as $k => $v) {
            if (!array_key_exists($k, self::DEFAULTS)) {
                continue;
            }
            DB::run(
                'INSERT INTO app_settings (skey, svalue) VALUES (:k, :v)
                 ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)',
                [':k' => $k, ':v' => (string) $v]
            );
        }
        self::$cache = null; // باطل‌سازی کش
    }
}
