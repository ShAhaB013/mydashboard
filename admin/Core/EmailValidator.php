<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// EmailValidator — منبع واحد اعتبارسنجی «حرفه‌ای» ایمیل
// لایه‌ها:
//   ۱) فرمت معتبر (FILTER_VALIDATE_EMAIL)
//   ۲) دامنه در فهرست ایمیل‌های موقتی/یک‌بارمصرف نباشد
//   ۳) دامنه‌های معتبر شناخته‌شده → مستقیما پذیرفته
//   ۴) تشخیص تایپوی دامنه‌های معروف (gmial/gmail2/…) با فاصله Levenshtein → رد
//   ۵) دامنه ناشناس → باید رکورد MX داشته باشد (دریافت ایمیل ممکن باشد)
// نکته: کد تایید ۶ رقمی (در api) لایه نهایی اثبات مالکیت ایمیل است.
// ═══════════════════════════════════════════════════════════

class EmailValidator
{
    /** دامنه‌های شناخته‌شده ایمیل موقتی/یک‌بارمصرف (رد می‌شوند) */
    public const DISPOSABLE_DOMAINS = [
        'mailinator.com', 'tempmail.com', 'temp-mail.org', 'guerrillamail.com',
        'guerrillamail.net', 'guerrillamail.org', 'sharklasers.com', '10minutemail.com',
        '10minutemail.net', 'yopmail.com', 'yopmail.net', 'trashmail.com', 'trashmail.net',
        'getnada.com', 'nada.email', 'dispostable.com', 'maildrop.cc', 'mailnesia.com',
        'mohmal.com', 'fakeinbox.com', 'throwawaymail.com', 'tempinbox.com', 'mailcatch.com',
        'emailondeck.com', 'spam4.me', 'mintemail.com', 'temp-mail.io', 'tmail.ws',
        'discard.email', 'mailtemp.net', 'inboxbear.com', 'tempr.email', 'moakt.com',
    ];

    /**
     * دامنه‌های معتبر پرکاربرد (whitelist). اگر دامنه اینجا باشد بدون
     * بررسی DNS پذیرفته می‌شود و از «تشخیص تایپو» مستثناست تا برای
     * نسخه‌های بین‌المللی/نزدیک (مثل email.com یا yahoo.co.uk) خطای کاذب ندهد.
     */
    public const KNOWN_GOOD_DOMAINS = [
        // Google
        'gmail.com', 'googlemail.com',
        // Yahoo
        'yahoo.com', 'yahoo.co.uk', 'yahoo.fr', 'yahoo.de', 'ymail.com', 'rocketmail.com',
        // Microsoft
        'hotmail.com', 'hotmail.co.uk', 'hotmail.fr', 'outlook.com', 'outlook.fr', 'outlook.sa',
        'live.com', 'live.co.uk', 'msn.com',
        // Apple
        'icloud.com', 'me.com', 'mac.com',
        // سایر معتبر
        'aol.com', 'protonmail.com', 'proton.me', 'pm.me', 'gmx.com', 'gmx.net',
        'mail.com', 'email.com', 'zoho.com', 'fastmail.com', 'hey.com',
        'tutanota.com', 'tuta.io', 'yandex.com', 'yandex.ru', 'mail.ru',
        'qq.com', '163.com', '126.com', 'naver.com',
    ];

    /** دامنه‌های معروفی که بیشترین تایپو روی آن‌ها رخ می‌دهد (مبنای تشخیص غلط املایی) */
    public const POPULAR_DOMAINS = [
        'gmail.com', 'googlemail.com', 'yahoo.com', 'ymail.com',
        'hotmail.com', 'outlook.com', 'live.com', 'msn.com',
        'icloud.com', 'aol.com', 'protonmail.com', 'proton.me',
    ];

    /**
     * اعتبارسنجی کامل ایمیل.
     * @return array{ok:bool, msg:string}
     */
    public static function validate(string $email): array
    {
        $email = trim($email);

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'msg' => 'ایمیل معتبر نیست'];
        }
        if (mb_strlen($email) > 190) {
            return ['ok' => false, 'msg' => 'ایمیل معتبر نیست'];
        }

        $domain = strtolower(substr($email, strrpos($email, '@') + 1));

        if (in_array($domain, self::DISPOSABLE_DOMAINS, true)) {
            return ['ok' => false, 'msg' => 'ایمیل‌های موقت/یک‌بارمصرف پذیرفته نمی‌شوند؛ یک ایمیل معتبر وارد کنید'];
        }

        // دامنه معتبر شناخته‌شده → پذیرش مستقیم
        if (in_array($domain, self::KNOWN_GOOD_DOMAINS, true)) {
            return ['ok' => true, 'msg' => ''];
        }

        // تشخیص تایپوی دامنه‌های معروف: اگر دامنه «خیلی نزدیک» به یک دامنه معروف
        // باشد ولی دقیقا همان نباشد (مثل gmial.com یا gmail2.com) → احتمالا اشتباه است.
        foreach (self::POPULAR_DOMAINS as $popular) {
            $dist = levenshtein($domain, $popular);
            if ($dist > 0 && $dist <= 2) {
                return ['ok' => false, 'msg' => "دامنه ایمیل معتبر نیست؛ شاید منظورتان «@{$popular}» بوده است"];
            }
        }

        // دامنه ناشناس (دامنه اختصاصی) → باید واقعا بتواند ایمیل دریافت کند
        if (!self::domainCanReceiveMail($domain)) {
            return ['ok' => false, 'msg' => 'ایمیل معتبر نیست'];
        }

        return ['ok' => true, 'msg' => ''];
    }

    /**
     * آیا دامنه می‌تواند ایمیل دریافت کند؟
     * طبق RFC 5321 اگر MX نبود، به رکورد A/AAAA همان دامنه fallback می‌شود؛
     * پس زیردامنه‌های معتبر (مثل tst.example.ir که فقط A دارند) هم پذیرفته می‌شوند.
     * اثباتِ نهاییِ مالکیت همان کدِ ۶ رقمی است.
     */
    private static function domainCanReceiveMail(string $domain): bool
    {
        if ($domain === '' || filter_var($domain, FILTER_VALIDATE_IP)) {
            return false;
        }
        if (!function_exists('checkdnsrr')) {
            return true; // اگر DNS در دسترس نباشد سخت‌گیری نکن (کد تایید همچنان اثبات مالکیت است)
        }
        return checkdnsrr($domain, 'MX')
            || checkdnsrr($domain, 'A')
            || checkdnsrr($domain, 'AAAA');
    }
}
