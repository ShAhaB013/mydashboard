<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// Mailer — sends email via SMTP (settings come from the admin panel / SettingsModel)
// If SMTP isn't enabled/configured, sending is skipped, and on local
// environments the verification code is returned as "dev" in the API response.
// SMTP is implemented with a raw socket (no external dependency): EHLO ->
// [STARTTLS] -> AUTH -> MAIL FROM -> RCPT TO -> DATA.
//
// Deliverability notes (Gmail / Outlook sender guidelines, RFC 5322):
//  - every message carries a unique Message-ID and a Date header
//  - EHLO announces *our* hostname (never the SMTP server's own name)
//  - non-ASCII headers are split into RFC 2047 encoded-words of <= 75 chars
//  - the plain-text part mirrors the HTML part (mismatch is a spam signal)
// ═══════════════════════════════════════════════════════════

class Mailer
{
    private const FONT = "font-family:Tahoma,'Segoe UI',Arial,sans-serif;";
    private const LTR_FONT = "font-family:'Segoe UI',Tahoma,Arial,sans-serif;";

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
        $ttlFa   = self::faDigits((string) $ttlMin);
        $brand   = self::brand();
        $subject = match ($purpose) {
            'reset'        => 'کد بازیابی رمز عبور',
            'email_change' => 'کد تایید تغییر ایمیل',
            default        => 'کد تایید ثبت‌نام',
        };
        $intro = match ($purpose) {
            'reset'        => 'درخواست بازیابی رمز عبور برای حساب کاربری شما ثبت شده است. برای ادامه، کد زیر را وارد کنید:',
            'email_change' => 'برای تایید تغییر ایمیل حساب کاربری خود، کد زیر را وارد کنید:',
            default        => 'برای تکمیل ثبت‌نام، کد زیر را وارد کنید:',
        };
        $expiry = "این کد تا {$ttlFa} دقیقه معتبر است و فقط یک بار قابل استفاده است.";
        $notice = 'اگر این درخواست از سوی شما نبوده است، این ایمیل را نادیده بگیرید. هرگز این کد را در اختیار دیگران قرار ندهید.';

        $text = self::textLayout($subject, [
            $intro,
            "کد: {$code}",
            $expiry,
            $notice,
        ]);

        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $f = self::FONT;
        $lf = self::LTR_FONT;
        $rows = <<<HTML
          <tr>
            <td style="padding:20px 28px;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td align="center" dir="ltr"
                      style="background-color:#eef4fd;border:1px dashed #3e7de7;border-radius:10px;padding:16px 8px;">
                    <span style="{$lf}font-size:30px;font-weight:bold;letter-spacing:10px;color:#2d6bd4;">{$e($code)}</span>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td style="padding:0 28px 8px;text-align:right;">
              <p style="{$f}margin:0;font-size:13px;line-height:2;color:#6b7280;">
                این کد تا <b style="color:#1f2937;">{$ttlFa} دقیقه</b> معتبر است و فقط یک بار قابل استفاده است.
              </p>
            </td>
          </tr>
HTML;
        $rows .= self::noticeRow($notice);

        $html = self::htmlLayout($subject, $e($intro), $rows, $brand);
        return self::send($to, self::withBrand($subject), $text, $html, $purpose);
    }

    /**
     * Sends a user's login credentials (used by the admin panel on user creation / manual reset).
     * @return array{ok:bool, error:string}
     */
    public static function sendCredentials(string $to, string $username, string $password, string $purpose = 'credentials'): array
    {
        $brand    = self::brand();
        $subject  = 'اطلاعات ورود به حساب کاربری';
        $loginUrl = self::loginUrl();
        $intro    = "حساب کاربری شما در {$brand} ایجاد یا بازنشانی شد. اطلاعات ورود شما به شرح زیر است:";
        $notice   = 'پیشنهاد می‌شود پس از ورود، رمز عبور خود را از بخش «حساب کاربری» تغییر دهید.';

        $lines = [$intro, "نام کاربری: {$username}\nرمز عبور: {$password}"];
        if ($loginUrl !== '') {
            $lines[] = "برای ورود به داشبورد از نشانی زیر استفاده کنید:\n{$loginUrl}";
        }
        $lines[] = $notice;
        $text = self::textLayout($subject, $lines);

        $e  = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $f  = self::FONT;
        $lf = self::LTR_FONT;
        $rows = <<<HTML
          <tr>
            <td style="padding:20px 28px 8px;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td style="background-color:#eef4fd;border:1px dashed #3e7de7;border-radius:10px;padding:16px 20px;text-align:right;">
                    <p style="{$f}margin:0 0 6px;font-size:13px;color:#4b5563;">نام کاربری</p>
                    <p dir="ltr" style="{$lf}margin:0 0 14px;font-size:18px;font-weight:bold;color:#2d6bd4;text-align:right;">{$e($username)}</p>
                    <p style="{$f}margin:0 0 6px;font-size:13px;color:#4b5563;">رمز عبور</p>
                    <p dir="ltr" style="{$lf}margin:0;font-size:18px;font-weight:bold;color:#2d6bd4;text-align:right;">{$e($password)}</p>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
HTML;
        // Login address as plain text, deliberately not a button: credentials + a "log in"
        // call-to-action is the classic phishing pattern mailbox filters look for.
        if ($loginUrl !== '') {
            $rows .= <<<HTML
          <tr>
            <td style="padding:12px 28px 4px;text-align:right;">
              <p style="{$f}margin:0 0 4px;font-size:13px;line-height:2;color:#4b5563;">برای ورود به داشبورد از نشانی زیر استفاده کنید:</p>
              <p dir="ltr" style="{$lf}margin:0;font-size:14px;color:#1f2937;text-align:right;word-break:break-all;">{$e($loginUrl)}</p>
            </td>
          </tr>
HTML;
        }
        $rows .= self::noticeRow($notice);

        $html = self::htmlLayout($subject, $e($intro), $rows, $brand);
        return self::send($to, self::withBrand($subject), $text, $html, $purpose);
    }

    /**
     * Sends the SMTP test email from the settings page (same layout as real emails,
     * so the test reflects how production emails are scored by spam filters).
     * @return array{ok:bool, error:string}
     */
    public static function sendTest(string $to): array
    {
        $brand   = self::brand();
        $subject = 'ایمیل آزمایشی';
        $intro   = 'این یک ایمیل آزمایشی است که از بخش تنظیمات داشبورد ارسال شده است.';
        $body    = 'اگر این ایمیل را دریافت کرده‌اید، تنظیمات SMTP به درستی کار می‌کند.';

        $text = self::textLayout($subject, [$intro, $body]);
        $html = self::htmlLayout(
            $subject,
            htmlspecialchars($intro, ENT_QUOTES, 'UTF-8'),
            self::noticeRow($body),
            $brand
        );
        return self::send($to, self::withBrand($subject), $text, $html, 'test');
    }

    // ── Templates ────────────────────────────────────────────

    /**
     * Subject line with the brand appended ("title | brand"). A bare generic subject such as
     * "password recovery code" matches phishing patterns; naming the sender helps recognition.
     */
    private static function withBrand(string $title): string
    {
        return $title . ' | ' . self::brand();
    }

    /** Sender display name, used as the brand in templates */
    private static function brand(): string
    {
        $brand = trim((string) SettingsModel::get('smtp_from_name'));
        return $brand !== '' ? $brand : 'داشبورد ابزارها';
    }

    /** Plain-text alternative that mirrors the HTML content */
    private static function textLayout(string $title, array $paragraphs): string
    {
        $brand = self::brand();
        return $title . "\n\n"
             . implode("\n\n", $paragraphs) . "\n\n"
             . "----\n"
             . "این ایمیل به صورت خودکار ارسال شده است؛ لطفا به آن پاسخ ندهید.\n"
             . $brand . ' - ' . self::faDigits((string) self::jalaliYear());
    }

    /** Muted notice box row */
    private static function noticeRow(string $text): string
    {
        $f = self::FONT;
        $t = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        return <<<HTML
          <tr>
            <td style="padding:16px 28px 28px;text-align:right;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td style="background-color:#f6f7f9;border:1px solid #e3e8ef;border-radius:10px;padding:10px 14px;text-align:right;">
                    <p style="{$f}margin:0;font-size:12px;line-height:2;color:#6b7280;">{$t}</p>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
HTML;
    }

    /** Shared RTL HTML shell (inline styles + tables for email-client compatibility) */
    private static function htmlLayout(string $title, string $introHtml, string $rows, string $brand): string
    {
        $f     = self::FONT;
        $brand = htmlspecialchars($brand, ENT_QUOTES, 'UTF-8');
        $title = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $year  = self::faDigits((string) self::jalaliYear());

        return <<<HTML
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$title}</title>
</head>
<body dir="rtl" style="margin:0;padding:0;background-color:#f2f4f8;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" dir="rtl" style="background-color:#f2f4f8;">
    <tr>
      <td align="center" style="padding:32px 16px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" dir="rtl"
               style="max-width:480px;background-color:#ffffff;border-radius:10px;overflow:hidden;border:1px solid #e3e8ef;">
          <tr>
            <td style="background-color:#3e7de7;padding:20px 28px;text-align:right;">
              <span style="{$f}font-size:16px;font-weight:bold;color:#ffffff;">{$brand}</span>
            </td>
          </tr>
          <tr>
            <td style="padding:28px 28px 8px;text-align:right;">
              <h1 style="{$f}margin:0 0 12px;font-size:18px;font-weight:bold;color:#1f2937;">{$title}</h1>
              <p style="{$f}margin:0;font-size:14px;line-height:2;color:#4b5563;">{$introHtml}</p>
            </td>
          </tr>
{$rows}
          <tr>
            <td style="border-top:1px solid #e3e8ef;padding:16px 28px;text-align:center;">
              <p style="{$f}margin:0;font-size:11px;line-height:1.9;color:#9ca3af;">
                این ایمیل به صورت خودکار ارسال شده است؛ لطفا به آن پاسخ ندهید.<br>
                تمامی حقوق برای {$brand} محفوظ است. {$year}
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }

    /** Converts Latin digits to Persian digits */
    private static function faDigits(string $s): string
    {
        return strtr($s, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
    }

    /** Current Solar Hijri (Jalali) year — the year rolls over at Nowruz (~March 21) */
    private static function jalaliYear(): int
    {
        $y = (int) date('Y');
        return ((int) date('md') >= 321) ? $y - 621 : $y - 622;
    }

    /** Best-effort absolute URL to the login page, based on the current request's host */
    private static function loginUrl(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
        $host = preg_replace('/[^a-zA-Z0-9.\-:]/', '', (string) $host);
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
              || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
              || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
        $scheme = $https ? 'https' : 'http';
        return $host !== '' ? "{$scheme}://{$host}/login.php" : '';
    }

    // ── Sending ──────────────────────────────────────────────

    /**
     * Sends an email via SMTP — plain text, or multipart/alternative when $html is given.
     * Every attempt (sent or failed) is recorded in email_logs; $purpose labels it there
     * (reset | register | email_change | credentials_new | credentials_reset | test | test_credentials | other).
     * @return array{ok:bool, error:string}
     */
    public static function send(string $to, string $subject, string $body, string $html = '', string $purpose = 'other'): array
    {
        $startedAt = microtime(true);
        $meta      = [];
        $res       = self::attempt($to, $subject, $body, $html, $meta);

        self::record([
            'purpose'       => $purpose,
            'recipient'     => $to,
            'subject'       => $subject,
            'status'        => $res['ok'] ? 'sent' : 'failed',
            'error'         => $res['ok'] ? null : $res['error'],
            'smtp_response' => $meta['smtp_response'] ?? null,
            'message_id'    => $res['ok'] ? ($meta['message_id'] ?? null) : null,
            'smtp_host'     => $meta['smtp_host'] ?? null,
            'duration_ms'   => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
        return $res;
    }

    /** Writes one email_logs row; if that fails (e.g. table not created yet) the attempt goes to error_logs instead. */
    private static function record(array $row): void
    {
        $row['user_id'] = class_exists('UserSession') ? (UserSession::id() ?: null) : null;
        $row['ip']      = $_SERVER['REMOTE_ADDR'] ?? null;
        try {
            EmailLogModel::record($row);
        } catch (\Throwable $e) {
            $level = $row['status'] === 'sent' ? 'info' : 'warning';
            $msg   = ($row['status'] === 'sent' ? 'ایمیل ارسال شد' : 'ارسال ایمیل ناموفق بود')
                   . ': ' . $row['recipient'] . ($row['error'] ? ' — ' . $row['error'] : '');
            try {
                Logger::{$level}($msg, $row + ['email_log_error' => $e->getMessage()]);
            } catch (\Throwable) {
                // Logging must never break sending
            }
        }
    }

    /**
     * Validates settings and performs the SMTP transaction. Fills $meta with
     * smtp_host / message_id / smtp_response for the email log.
     * @return array{ok:bool, error:string}
     */
    private static function attempt(string $to, string $subject, string $body, string $html, array &$meta): array
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
        $subject      = preg_replace('/[\x00-\x1F\x7F]/', '', $subject);

        $meta['smtp_host']  = $cfg['host'] . ':' . $cfg['port'];
        $meta['message_id'] = bin2hex(random_bytes(16)) . '@' . substr((string) strrchr($cfg['from'], '@'), 1);

        try {
            return self::smtpSend($to, $subject, $body, $html, $cfg, $meta);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // ── SMTP implementation ──────────────────────────────────

    /** @param array{host:string,port:int,secure:string,user:string,pass:string,from:string,fname:string} $cfg */
    private static function smtpSend(string $to, string $subject, string $body, string $html, array $cfg, array &$meta): array
    {
        $transport = $cfg['secure'] === 'ssl' ? 'ssl://' : '';
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $fp  = @stream_socket_client(
            $transport . $cfg['host'] . ':' . $cfg['port'],
            $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx
        );
        if (!$fp) {
            $meta['smtp_response'] = "{$errno}: {$errstr}";
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
        $expect = function (string $resp, string $code) use (&$err, &$meta): bool {
            // Keep the last server reply for the email log (the final "250 OK id=..." holds the queue id)
            $meta['smtp_response'] = trim(preg_replace('/\s+/', ' ', $resp));
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

        $fromDomain = substr((string) strrchr($cfg['from'], '@'), 1);
        $ehloHost   = self::heloHostname($fromDomain);

        if (!$expect($read(), '220')) return $fail($err);
        $ehlo = $cmd('EHLO ' . $ehloHost);
        if (!$expect($ehlo, '250')) return $fail($err);

        // STARTTLS for tls mode
        if ($cfg['secure'] === 'tls') {
            if (!$expect($cmd('STARTTLS'), '220')) return $fail($err);
            $crypto = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
                    | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0);
            if (!@stream_socket_enable_crypto($fp, true, $crypto)) {
                return $fail('برقراری TLS ناموفق بود');
            }
            $ehlo = $cmd('EHLO ' . $ehloHost);
            if (!$expect($ehlo, '250')) return $fail($err);
        }

        // Authenticate (if a username is set): AUTH LOGIN, or AUTH PLAIN when LOGIN isn't offered
        if ($cfg['user'] !== '') {
            $authLine = preg_match('/^250[ -]AUTH[ =](.*)$/mi', $ehlo, $m) ? strtoupper($m[1]) : '';
            $authFail = 'احراز هویت SMTP ناموفق بود (نام کاربری/رمز)';
            if ($authLine !== '' && !str_contains($authLine, 'LOGIN') && str_contains($authLine, 'PLAIN')) {
                $token = base64_encode("\0" . $cfg['user'] . "\0" . $cfg['pass']);
                if (!$expect($cmd('AUTH PLAIN ' . $token), '235')) return $fail($authFail);
            } else {
                if (!$expect($cmd('AUTH LOGIN'), '334')) return $fail($err);
                if (!$expect($cmd(base64_encode($cfg['user'])), '334')) return $fail($err);
                if (!$expect($cmd(base64_encode($cfg['pass'])), '235')) return $fail($authFail);
            }
        }

        if (!$expect($cmd('MAIL FROM:<' . $cfg['from'] . '>'), '250')) return $fail($err);
        if (!$expect($cmd('RCPT TO:<' . $to . '>'), '25')) return $fail($err); // 250/251
        if (!$expect($cmd('DATA'), '354')) return $fail($err);

        $from     = $cfg['fname'] !== ''
                  ? self::encodeHeader($cfg['fname']) . ' <' . $cfg['from'] . '>'
                  : $cfg['from'];
        $headers  = 'Date: ' . date('D, d M Y H:i:s O') . "\r\n"
                  . 'Message-ID: <' . $meta['message_id'] . ">\r\n"
                  . 'From: ' . $from . "\r\n"
                  . 'To: ' . $to . "\r\n"
                  . 'Subject: ' . self::encodeHeader($subject) . "\r\n"
                  . 'MIME-Version: 1.0' . "\r\n"
                  . 'Content-Language: fa' . "\r\n"
                  . 'Auto-Submitted: auto-generated' . "\r\n";

        // No need for leading-dot stuffing since the bodies are base64
        if ($html !== '') {
            // multipart/alternative: plain-text fallback first, HTML preferred
            $boundary = 'b_' . bin2hex(random_bytes(12));
            $headers .= 'Content-Type: multipart/alternative; boundary="' . $boundary . '"' . "\r\n";
            $mime  = '--' . $boundary . "\r\n"
                   . 'Content-Type: text/plain; charset=UTF-8' . "\r\n"
                   . 'Content-Transfer-Encoding: base64' . "\r\n\r\n"
                   . chunk_split(base64_encode(self::crlf($body)))
                   . '--' . $boundary . "\r\n"
                   . 'Content-Type: text/html; charset=UTF-8' . "\r\n"
                   . 'Content-Transfer-Encoding: base64' . "\r\n\r\n"
                   . chunk_split(base64_encode(self::crlf($html)))
                   . '--' . $boundary . '--' . "\r\n";
            $data = $headers . "\r\n" . $mime;
        } else {
            $headers .= 'Content-Type: text/plain; charset=UTF-8' . "\r\n"
                      . 'Content-Transfer-Encoding: base64' . "\r\n";
            $data = $headers . "\r\n" . chunk_split(base64_encode(self::crlf($body)));
        }
        fwrite($fp, $data . ".\r\n");
        if (!$expect($read(), '250')) return $fail($err);

        $cmd('QUIT');
        @fclose($fp);
        return ['ok' => true, 'error' => ''];
    }

    /**
     * Hostname announced in EHLO. Must be a real FQDN that identifies *us* —
     * announcing the SMTP server's own name (the old behavior) looks forged to
     * spam filters. Prefers the machine's hostname (usually matches the host's
     * reverse DNS on shared hosting), then the site's domain, then the sender's domain.
     */
    private static function heloHostname(string $fromDomain): string
    {
        $isFqdn = static fn(string $h): bool =>
            (bool) preg_match('/^(?=.{4,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $h);

        foreach ([(string) gethostname(), (string) ($_SERVER['SERVER_NAME'] ?? ''), $fromDomain] as $h) {
            if ($isFqdn($h)) return strtolower($h);
        }
        return 'localhost.localdomain';
    }

    /** Normalizes line endings to CRLF (RFC 5322) */
    private static function crlf(string $s): string
    {
        return preg_replace('/\r\n|\r|\n/', "\r\n", $s);
    }

    /**
     * Encodes non-ASCII headers (RFC 2047). Each encoded-word is kept within the
     * 75-char limit by splitting on UTF-8 character boundaries, folded onto new lines.
     */
    private static function encodeHeader(string $text): string
    {
        if ($text === '' || preg_match('/^[\x20-\x7E]*$/', $text)) {
            return $text;
        }
        $words = [];
        $chunk = '';
        foreach (mb_str_split($text, 1, 'UTF-8') as $ch) {
            // 45 bytes -> 60 base64 chars; "=?UTF-8?B?" + "?=" brings it to 72 (<= 75)
            if (strlen($chunk) + strlen($ch) > 45) {
                $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
                $chunk = '';
            }
            $chunk .= $ch;
        }
        if ($chunk !== '') {
            $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
        }
        return implode("\r\n ", $words);
    }
}
