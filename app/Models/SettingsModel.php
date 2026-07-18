<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// SettingsModel — app settings as key/value pairs in the app_settings table
// (SMTP email server + OTP code timing)
// ═══════════════════════════════════════════════════════════

class SettingsModel
{
    /** Allowed keys + default value (only these keys are stored) */
    public const DEFAULTS = [
        'session_ttl_hours' => '24',  // hours — how long a user session stays active
        'smtp_enabled'    => '0',
        'smtp_host'       => '',
        'smtp_port'       => '587',
        'smtp_secure'     => 'tls',   // tls | ssl | none
        'smtp_user'       => '',
        'smtp_pass'       => '',
        'smtp_from_email' => '',
        'smtp_from_name'  => 'داشبورد ابزارها',
        'resend_cooldown' => '30',    // seconds — allowed interval between resending the code
        'code_ttl'        => '600',   // seconds — how long the OTP code stays valid
        'debug_mode'      => '0',     // '1' -> ErrorHandler includes file/line/trace in error responses
    ];

    /** In-request cache so we don't hit the DB repeatedly */
    private static ?array $cache = null;

    /** All settings as a map (with defaults applied for missing keys) */
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
            // Table doesn't exist yet -> use defaults
        }
        // smtp_pass may be stored encrypted (Crypto); old plaintext values are
        // returned unchanged (compatible with existing installs).
        $out['smtp_pass'] = Crypto::decrypt($out['smtp_pass']);
        return self::$cache = $out;
    }

    /** Reads a single key */
    public static function get(string $key, ?string $default = null): ?string
    {
        $all = self::all();
        return $all[$key] ?? $default ?? (self::DEFAULTS[$key] ?? null);
    }

    /** Reads a key as an integer, clamped to min/max */
    public static function getInt(string $key, int $min, int $max, int $fallback): int
    {
        $v = (int) self::get($key, (string) $fallback);
        if ($v < $min) return $min;
        if ($v > $max) return $max;
        return $v;
    }

    /** Bulk save — only allowed keys are applied */
    public static function setMany(array $kv): void
    {
        foreach ($kv as $k => $v) {
            if (!array_key_exists($k, self::DEFAULTS)) {
                continue;
            }
            $v = (string) $v;
            if ($k === 'smtp_pass') {
                $v = Crypto::encrypt($v);
            }
            DB::run(
                'INSERT INTO app_settings (skey, svalue) VALUES (:k, :v)
                 ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)',
                [':k' => $k, ':v' => $v]
            );
        }
        self::$cache = null; // invalidate the cache
    }
}
