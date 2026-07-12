<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// Mailer — sends email via SMTP (settings come from the admin panel / SettingsModel)
// If SMTP isn't enabled/configured, sending is skipped, and on local
// environments the verification code is returned as "dev" in the API response.
// SMTP is implemented with a raw socket (no external dependency): EHLO ->
// [STARTTLS] -> AUTH LOGIN -> MAIL FROM -> RCPT TO -> DATA.
// ═══════════════════════════════════════════════════════════

class Mailer
{
    /** Are we on a local environment? (so the code can be shown for testing) */
    public static function isLocal(): bool
    {
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
        return (bool) preg_match('/^(127\.0\.0\.1|localhost|::1)(:\d+)?$/i', $host);
    }

    /** Is SMTP enabled and at least the host configured? */
    public static function isConfigured(): bool
    {
        return SettingsModel::get('smtp_enabled') === '1'
            && trim((string) SettingsModel::get('smtp_host')) !== '';
    }

    /**
     * Are we allowed to expose the verification code in the API response? (local dev only)
     * When SMTP isn't configured, the verification code is returned in the
     * API response so testing is possible without email.
     */
    public static function devCodeAllowed(): bool
    {
        return !self::isConfigured();
    }

    /**
     * Sends a verification/recovery code.
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
     * Sends a plain-text email via SMTP.
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

        // Re-validate the sender right before sending (defense in depth;
        // independent of the save-time validation in SettingsController) +
        // strip control characters from the display name (header/SMTP command injection).
        if (!filter_var($cfg['from'], FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'آدرس فرستنده نامعتبر است'];
        }
        $cfg['fname'] = preg_replace('/[\x00-\x1F\x7F]/', '', $cfg['fname']);

        try {
            return self::smtpSend($to, $subject, $body, $cfg);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // ── SMTP implementation ──────────────────────────────────

    /** @param array{host:string,port:int,secure:string,user:string,pass:string,from:string,fname:string} $cfg */
    private static function smtpSend(string $to, string $subject, string $body, array $cfg): array
    {
        $transport = $cfg['secure'] === 'ssl' ? 'ssl://' : '';
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
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
                // Multi-line response: a '-' as the 4th character means more lines follow
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

        // STARTTLS for tls mode
        if ($cfg['secure'] === 'tls') {
            if (!$expect($cmd('STARTTLS'), '220')) return $fail($err);
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT
                    | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT)) {
                return $fail('برقراری TLS ناموفق بود');
            }
            if (!$expect($cmd('EHLO ' . $ehloHost), '250')) return $fail($err);
        }

        // AUTH LOGIN (if a username is set)
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

        // No need for leading-dot stuffing since the body is base64
        $data = $headers . "\r\n" . chunk_split(base64_encode($body));
        fwrite($fp, $data . "\r\n.\r\n");
        if (!$expect($read(), '250')) return $fail($err);

        $cmd('QUIT');
        @fclose($fp);
        return ['ok' => true, 'error' => ''];
    }

    /** Encodes non-ASCII headers (RFC 2047) */
    private static function encodeHeader(string $text): string
    {
        if ($text === '' || preg_match('/^[\x20-\x7E]*$/', $text)) {
            return $text;
        }
        return '=?UTF-8?B?' . base64_encode($text) . '?=';
    }
}
