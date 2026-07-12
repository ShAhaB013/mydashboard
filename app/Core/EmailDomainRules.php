<?php
// ═══════════════════════════════════════════════════════════
// EmailDomainRules — static email domain validation (no DNS)
//   • list of valid TLDs (needs occasional manual updates)
//   • common typo detection against popular email domains
// ═══════════════════════════════════════════════════════════

class EmailDomainRules
{
    // List of common, known TLDs (static snapshot; not exhaustive but covers
    // the main gTLDs, popular ccTLDs, and common new gTLDs).
    private const VALID_TLDS = [
        // classic gTLDs
        'com', 'net', 'org', 'info', 'biz', 'name', 'pro', 'mobi', 'travel',
        'jobs', 'coop', 'aero', 'museum', 'int', 'edu', 'gov', 'mil',
        // popular new gTLDs
        'io', 'co', 'dev', 'app', 'xyz', 'me', 'tv', 'cc', 'online', 'site',
        'store', 'tech', 'shop', 'blog', 'cloud', 'digital', 'email', 'live',
        'news', 'space', 'website', 'world', 'club', 'design', 'agency',
        'company', 'group', 'studio', 'network', 'systems', 'solutions',
        'services', 'software', 'today', 'zone', 'academy', 'center', 'expert',
        // popular ccTLDs
        'ir', 'us', 'uk', 'de', 'fr', 'nl', 'ru', 'cn', 'jp', 'kr', 'in',
        'br', 'ca', 'au', 'es', 'it', 'ch', 'se', 'no', 'dk', 'fi', 'pl',
        'tr', 'ae', 'sa', 'eg', 'iq', 'af', 'pk', 'id', 'my', 'sg', 'th',
        'vn', 'ph', 'nz', 'za', 'mx', 'ar', 'cl', 'co', 'pt', 'gr', 'cz',
        'at', 'be', 'ie', 'il', 'hk', 'tw', 'ua', 'ro', 'hu', 'bg', 'sk',
        'eu', 'asia',
    ];

    // Popular email domains, used to detect near-typos
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

    // Returns the closest popular domain if the edit distance is 1-2
    // (i.e. likely a typo, not a genuinely different valid domain)
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
