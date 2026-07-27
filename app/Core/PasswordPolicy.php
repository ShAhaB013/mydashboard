<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// PasswordPolicy — single source of truth for password rules
// Explicit rules (all mandatory) — must exactly match the client-side live checklist:
//   - length between MIN_LENGTH and MAX_LENGTH
//   - at least one lowercase English letter
//   - at least one uppercase English letter
//   - at least one digit
//   - at least one symbol (not an English letter/digit)
// ═══════════════════════════════════════════════════════════

class PasswordPolicy
{
    /** Minimum allowed length (security floor) */
    public const MIN_LENGTH = 10;

    /** Maximum allowed length (bcrypt effectively only considers up to ~72 bytes anyway) */
    public const MAX_LENGTH = 64;

    /** Does the password satisfy all the rules? (must stay in sync with the JS version) */
    public static function isAcceptable(string $pw): bool
    {
        $len = mb_strlen($pw);
        return $len >= self::MIN_LENGTH
            && $len <= self::MAX_LENGTH
            && preg_match('/[a-z]/', $pw)
            && preg_match('/[A-Z]/', $pw)
            && preg_match('/[0-9]/', $pw)
            && preg_match('/[^A-Za-z0-9]/', $pw);
    }

    /** Standard error message for an invalid password */
    public static function errorMessage(): string
    {
        return 'رمز عبور باید بین ۱۰ تا ۶۴ کاراکتر و شامل حروف کوچک و بزرگ انگلیسی، عدد و نماد باشد.';
    }

    /** Generates a strong random password (kept in sync with PasswordPolicy.generate() in the client-side JS) */
    public static function generate(): string
    {
        $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ'; // excludes ambiguous I, O
        $lower = 'abcdefghijkmnopqrstuvwxyz'; // excludes ambiguous l
        $digit = '23456789';                  // excludes ambiguous 0, 1
        $symbol = '!@#$%^&*-_=+?';
        $all = $upper . $lower . $digit . $symbol;

        $pick = static fn(string $set): string => $set[random_int(0, strlen($set) - 1)];

        $len = random_int(14, 18);
        $chars = [$pick($upper), $pick($lower), $pick($digit), $pick($symbol)];
        while (count($chars) < $len) {
            $chars[] = $pick($all);
        }
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }
        return implode('', $chars);
    }
}
