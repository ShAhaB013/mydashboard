<?php
// ═══════════════════════════════════════════════════════════
// Validator — validates inputs
// ═══════════════════════════════════════════════════════════

class Validator
{
    /**
     * A tool path must:
     * - not be empty
     * - not contain javascript: / data: / ..
     * - consist only of allowed characters
     */
    public static function isValidPath(string $path): bool
    {
        if (empty($path)) {
            return false;
        }

        if (preg_match('/^(javascript:|data:|vbscript:|blob:)/i', $path)) {
            return false;
        }

        if (strpos($path, '..') !== false) {
            return false;
        }

        // Support external links (http / https)
        if (preg_match('/^https?:\/\/.+/i', $path)) {
            return (bool) filter_var($path, FILTER_VALIDATE_URL);
        }

        return (bool) preg_match('/^(\/[\w\-\.\/]*|[\w\-][\w\-\.\/]*)$/', $path);
    }

    /**
     * An icon/animation key must:
     * - start with an English letter
     * - consist only of letters, digits, hyphens, and underscores
     * - be at most 40 characters
     */
    public static function isValidKey(string $key): bool
    {
        return (bool) preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,39}$/', $key);
    }

    /**
     * Validates first/last name: 2 to 60 characters, letters only
     * (Persian/English) plus spaces, hyphens, and apostrophes; no digits or symbols.
     * Single source of truth — shared between public registration (api.php)
     * and admin add/edit (UserController).
     * @return string error message, or '' if valid.
     */
    public static function name(string $name, string $label): string
    {
        $len = mb_strlen($name);
        if ($len < 2 || $len > 60) {
            return "$label باید بین ۲ تا ۶۰ کاراکتر باشد";
        }
        // Unicode letters (including Persian) + space/hyphen/apostrophe
        if (!preg_match("/^[\p{L}\p{M}][\p{L}\p{M}\s'’\-]*$/u", $name)) {
            return "$label فقط می‌تواند شامل حروف باشد";
        }
        return '';
    }

    /**
     * Validates username: 3 to 60 characters, starts with an English letter,
     * only letters/digits/underscore.
     * @return string error message, or '' if valid.
     */
    public static function username(string $username): string
    {
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{2,59}$/', $username)) {
            return 'نام‌کاربری باید با حرف انگلیسی شروع شود و فقط شامل حروف/اعداد/underscore باشد (۳ تا ۶۰ کاراکتر)';
        }
        return '';
    }

    /**
     * Validates a category name: required, at most 20 characters, letters only
     * (Persian/English, no digits/spaces/hyphens) plus underscore. Single source
     * of truth — shared between the tool editor (which creates categories via
     * CategoryModel::findOrCreateByName) and the category management page (rename).
     * @return string error message, or '' if valid.
     */
    public static function categoryName(string $name): string
    {
        if ($name === '') {
            return 'نام دسته‌بندی الزامی است';
        }
        if (mb_strlen($name) > 20) {
            return 'نام دسته‌بندی نباید بیشتر از ۲۰ کاراکتر باشد';
        }
        // Unicode letters (including Persian) + underscore only — no digits, spaces, or symbols.
        if (!preg_match('/^[\p{L}_]+$/u', $name)) {
            return 'نام دسته‌بندی فقط می‌تواند شامل حروف و underscore باشد';
        }
        return '';
    }

    /**
     * Validates an Iranian mobile number: 11 digits, starts with 09.
     * @return string error message, or '' if valid.
     */
    public static function phone(string $phone): string
    {
        if (!preg_match('/^09\d{9}$/', $phone)) {
            return 'شماره موبایل باید ۱۱ رقم و با ۰۹ شروع شود';
        }
        return '';
    }

    /**
     * Validates email: valid format + at most 190 characters.
     * @return string error message, or '' if valid.
     */
    public static function email(string $email): string
    {
        if (mb_strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'ایمیل معتبر نیست';
        }
        $domain = substr(strrchr($email, '@'), 1);
        if (!EmailDomainRules::isValidTld($domain)) {
            return 'دامنه ایمیل معتبر نیست';
        }
        $suggestion = EmailDomainRules::suggestCorrection($domain);
        if ($suggestion !== null) {
            $user = substr($email, 0, strpos($email, '@'));
            return "به نظر می‌رسد منظورتان {$user}@{$suggestion} بوده؛ لطفا ایمیل را بررسی کنید";
        }
        return '';
    }
}
