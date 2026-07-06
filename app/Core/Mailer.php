<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// Mailer — ارسال ایمیل از طریق SMTP (تنظیمات از پنل ادمین / SettingsModel)
// اگر SMTP فعال/پیکربندی نشده باشد، ارسال انجام نمی‌شود و در محیط محلی
// کد تایید به‌صورت dev در پاسخ API برمی‌گردد.
// پیاده‌سازی SMTP با سوکت خام است (بدون وابستگی بیرونی): EHLO →
// [STARTTLS] → AUTH LOGIN → MAIL FROM → RCPT TO → DATA.
// ═══════════════════════════════════════════════════════════

class Mailer
{
    /** آیا روی محیط محلی هستیم؟ (برای نمایش کد جهت تست) */
    public static function isLocal(): bool
    {
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
        return (bool) preg_match('/^(127\.0\.0\.1|localhost|::1)(:\d+)?$/i', $host);
    }

    /** آیا SMTP فعال و حداقل host تنظیم شده است؟ */
    public static function isConfigured(): bool
    {
        return SettingsModel::get('smtp_enabled') === '1'
            && trim((string) SettingsModel::get('smtp_host')) !== '';
    }

    /**
     * آیا مجاز به افشای کد تایید در پاسخ API هستیم؟ (فقط برای توسعه محلی)
     * وقتی SMTP پیکربندی نشده باشد، کد تایید در پاسخ API برمی‌گردد
     * تا بدون ایمیل هم بتوان تست کرد.
     */
    public static function devCodeAllowed(): bool
    {
        return !self::isConfigured();
    }

    /**
     * ارسال کد تایید/بازیابی.
     * @return array{ok:bool, error:string}
     */
    public static function sendCode(string $to, string $code, string $purpose = 'register'): array
    {
        $ttlMin  = (int) ceil(SettingsModel::getInt('code_ttl', 60, 86400, 600) / 60);
        $subject = match ($purpose) {
            'reset'        => 'کد بازیابی رمز عبور',
            'email_change' => 'کد تایید تغییر ایمیل',
            default        => 'کد تایید ثبت‌نام',
        };
        $body    = "کد شما: {$code}\n\n"
                 . "این کد تا {$ttlMin} دقیقه معتبر است.\n"
                 . "اگر شما این درخواست را نداده‌اید، این پیام را نادیده بگیرید.";
        return self::send($to, $subject, $body);
    }

    /**
     * ارسال یک ایمیل متنی از طریق SMTP.
     * @return array{ok:bool, error:string}
     */
    public static function send(string $to, string $subject, string $body): array
    {
        if (!self::isConfigured()) {
            return ['ok' => false, 'error' => 'SMTP فعال یا پیکربندی نشده است'];
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'گیرنده ایمیل نامعتبر است'];
        }

        $cfg = [
            'host'   => trim((string) SettingsModel::get('smtp_host')),
            'port'   => SettingsModel::getInt('smtp_port', 1, 65535, 587),
            'secure' => strtolower((string) SettingsModel::get('smtp_secure')), // tls|ssl|none
            'user'   => (string) SettingsModel::get('smtp_user'),
            'pass'   => (string) SettingsModel::get('smtp_pass'),
            'from'   => trim((string) SettingsModel::get('smtp_from_email')),
            'fname'  => (string) SettingsModel::get('smtp_from_name'),
        ];
        if ($cfg['from'] === '') {
            $cfg['from'] = $cfg['user'];
        }

        try {
            return self::smtpSend($to, $subject, $body, $cfg);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // ── پیاده‌سازی SMTP ─────────────────────────────────────

    /** @param array{host:string,port:int,secure:string,user:string,pass:string,from:string,fname:string} $cfg */
    private static function smtpSend(string $to, string $subject, string $body, array $cfg): array
    {
        $transport = $cfg['secure'] === 'ssl' ? 'ssl://' : '';
        $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $fp  = @stream_socket_client(
            $transport . $cfg['host'] . ':' . $cfg['port'],
            $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx
        );
        if (!$fp) {
            return ['ok' => false, 'error' => "اتصال به سرور SMTP ناموفق بود ({$errno}: {$errstr})"];
        }
        stream_set_timeout($fp, 15);

        $err = '';
        $read = function () use ($fp): string {
            $data = '';
            while (($line = fgets($fp, 515)) !== false) {
                $data .= $line;
                // خطوط چندتایی: کاراکتر چهارم '-' یعنی ادامه دارد
                if (strlen($line) < 4 || $line[3] === ' ') break;
            }
            return $data;
        };
        $cmd = function (string $c) use ($fp, $read): string {
            fwrite($fp, $c . "\r\n");
            return $read();
        };
        $expect = function (string $resp, string $code) use (&$err): bool {
            if (strncmp($resp, $code, strlen($code)) !== 0) {
                $err = 'پاسخ غیرمنتظره از SMTP: ' . trim($resp);
                return false;
            }
            return true;
        };

        $fail = function (string $msg) use ($fp): array {
            @fclose($fp);
            return ['ok' => false, 'error' => $msg];
        };

        $ehloHost = preg_replace('/[^a-zA-Z0-9.\-]/', '', $cfg['host']) ?: 'localhost';

        if (!$expect($read(), '220')) return $fail($err);
        if (!$expect($cmd('EHLO ' . $ehloHost), '250')) return $fail($err);

        // STARTTLS برای حالت tls
        if ($cfg['secure'] === 'tls') {
            if (!$expect($cmd('STARTTLS'), '220')) return $fail($err);
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT
                    | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT)) {
                return $fail('برقراری TLS ناموفق بود');
            }
            if (!$expect($cmd('EHLO ' . $ehloHost), '250')) return $fail($err);
        }

        // AUTH LOGIN (در صورت داشتن نام کاربری)
        if ($cfg['user'] !== '') {
            if (!$expect($cmd('AUTH LOGIN'), '334')) return $fail($err);
            if (!$expect($cmd(base64_encode($cfg['user'])), '334')) return $fail($err);
            if (!$expect($cmd(base64_encode($cfg['pass'])), '235')) return $fail('احراز هویت SMTP ناموفق بود (نام کاربری/رمز)');
        }

        if (!$expect($cmd('MAIL FROM:<' . $cfg['from'] . '>'), '250')) return $fail($err);
        if (!$expect($cmd('RCPT TO:<' . $to . '>'), '25')) return $fail($err); // 250/251
        if (!$expect($cmd('DATA'), '354')) return $fail($err);

        $fromName = self::encodeHeader($cfg['fname']);
        $headers  = 'From: ' . $fromName . ' <' . $cfg['from'] . ">\r\n"
                  . 'To: <' . $to . ">\r\n"
                  . 'Subject: ' . self::encodeHeader($subject) . "\r\n"
                  . 'MIME-Version: 1.0' . "\r\n"
                  . 'Content-Type: text/plain; charset=UTF-8' . "\r\n"
                  . 'Content-Transfer-Encoding: base64' . "\r\n"
                  . 'Date: ' . date('r') . "\r\n";

        // نقطه‌گذاری ابتدای خط (dot-stuffing) لازم نیست چون بدنه base64 است
        $data = $headers . "\r\n" . chunk_split(base64_encode($body));
        fwrite($fp, $data . "\r\n.\r\n");
        if (!$expect($read(), '250')) return $fail($err);

        $cmd('QUIT');
        @fclose($fp);
        return ['ok' => true, 'error' => ''];
    }

    /** کدگذاری هدر غیر-ASCII (RFC 2047) */
    private static function encodeHeader(string $text): string
    {
        if ($text === '' || preg_match('/^[\x20-\x7E]*$/', $text)) {
            return $text;
        }
        return '=?UTF-8?B?' . base64_encode($text) . '?=';
    }
}
