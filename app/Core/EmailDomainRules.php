<?php
// ═══════════════════════════════════════════════════════════
// EmailDomainRules — بررسی ایستای دامنه ایمیل (بدون DNS)
//   • فهرست TLDهای معتبر (باید هرازگاهی دستی به‌روزرسانی شود)
//   • تشخیص غلط املایی رایج نسبت به دامنه‌های پرکاربرد ایمیل
// ═══════════════════════════════════════════════════════════

class EmailDomainRules
{
    // فهرست TLDهای رایج و شناخته‌شده (snapshot ایستا؛ کامل نیست ولی پوشش
    // gTLDهای اصلی، ccTLDهای پرکاربرد و new gTLDهای رایج را می‌دهد).
    private const VALID_TLDS = [
        // gTLDs کلاسیک
        'com', 'net', 'org', 'info', 'biz', 'name', 'pro', 'mobi', 'travel',
        'jobs', 'coop', 'aero', 'museum', 'int', 'edu', 'gov', 'mil',
        // new gTLDs پرکاربرد
        'io', 'co', 'dev', 'app', 'xyz', 'me', 'tv', 'cc', 'online', 'site',
        'store', 'tech', 'shop', 'blog', 'cloud', 'digital', 'email', 'live',
        'news', 'space', 'website', 'world', 'club', 'design', 'agency',
        'company', 'group', 'studio', 'network', 'systems', 'solutions',
        'services', 'software', 'today', 'zone', 'academy', 'center', 'expert',
        // ccTLDs پرکاربرد
        'ir', 'us', 'uk', 'de', 'fr', 'nl', 'ru', 'cn', 'jp', 'kr', 'in',
        'br', 'ca', 'au', 'es', 'it', 'ch', 'se', 'no', 'dk', 'fi', 'pl',
        'tr', 'ae', 'sa', 'eg', 'iq', 'af', 'pk', 'id', 'my', 'sg', 'th',
        'vn', 'ph', 'nz', 'za', 'mx', 'ar', 'cl', 'co', 'pt', 'gr', 'cz',
        'at', 'be', 'ie', 'il', 'hk', 'tw', 'ua', 'ro', 'hu', 'bg', 'sk',
        'eu', 'asia',
    ];

    // دامنه‌های پرکاربرد ایمیل برای تشخیص غلط املایی نزدیک
    private const POPULAR_DOMAINS = [
        'gmail.com', 'yahoo.com', 'outlook.com', 'hotmail.com', 'icloud.com',
        'live.com', 'ymail.com', 'aol.com', 'protonmail.com', 'zoho.com',
        'msn.com', 'mail.com', 'gmx.com', 'yandex.com',
    ];

    public static function isValidTld(string $domain): bool
    {
        $lastDot = strrchr($domain, '.');
        if ($lastDot === false || strlen($lastDot) < 2) {
            return false;
        }
        $tld = strtolower(substr($lastDot, 1));
        return in_array($tld, self::VALID_TLDS, true);
    }

    // نزدیک‌ترین دامنه‌ی محبوب را برمی‌گرداند اگر فاصله‌ی ادیت بین ۱ تا ۲ باشد
    // (یعنی احتمالاً غلط املایی است، نه دامنه‌ای متفاوت و معتبر)
    public static function suggestCorrection(string $domain): ?string
    {
        $domain = strtolower($domain);
        if (in_array($domain, self::POPULAR_DOMAINS, true)) {
            return null;
        }
        $best     = null;
        $bestDist = PHP_INT_MAX;
        foreach (self::POPULAR_DOMAINS as $known) {
            $dist = levenshtein($domain, $known);
            if ($dist < $bestDist) {
                $bestDist = $dist;
                $best     = $known;
            }
        }
        return ($bestDist >= 1 && $bestDist <= 2) ? $best : null;
    }
}
