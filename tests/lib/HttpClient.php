<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// HttpClient — a lightweight cURL client for API tests with an independent cookie jar
// (each instance simulates a separate browser "session": logged-out/user/admin)
// ═══════════════════════════════════════════════════════════

class HttpClient
{
    private string $baseUrl;
    private string $cookieFile;
    private ?string $csrfToken = null;

    public function __construct(string $baseUrl)
    {
        $this->baseUrl    = rtrim($baseUrl, '/');
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'dascookie_');
    }

    public function __destruct()
    {
        if (is_file($this->cookieFile)) @unlink($this->cookieFile);
    }

    public function setCsrfToken(?string $token): void
    {
        $this->csrfToken = $token;
    }

    public function csrfToken(): ?string
    {
        return $this->csrfToken;
    }

    public function resetCookies(): void
    {
        if (is_file($this->cookieFile)) @unlink($this->cookieFile);
        $this->csrfToken = null;
    }

    /** Generic JSON request. $body is converted to JSON if it's an array. */
    public function request(string $method, string $path, ?array $body = null, array $headers = [], bool $withCsrf = true): array
    {
        $url = str_starts_with($path, 'http') ? $path : $this->baseUrl . $path;

        $ch = curl_init($url);
        $hdrs = ['Accept: application/json'];
        if ($body !== null) $hdrs[] = 'Content-Type: application/json';
        if ($withCsrf && $this->csrfToken !== null) $hdrs[] = 'X-CSRF-Token: ' . $this->csrfToken;
        foreach ($headers as $h) $hdrs[] = $h;

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_COOKIEJAR      => $this->cookieFile,
            CURLOPT_COOKIEFILE     => $this->cookieFile,
            CURLOPT_HTTPHEADER     => $hdrs,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }

        $t0 = microtime(true);
        $raw = curl_exec($ch);
        $timeMs = (microtime(true) - $t0) * 1000;

        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return ['status' => 0, 'headers' => [], 'body' => '', 'json' => null, 'time_ms' => $timeMs, 'error' => $err];
        }

        $status     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $rawHeaders = substr($raw, 0, $headerSize);
        $rawBody    = substr($raw, $headerSize);

        $respHeaders = [];
        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (strpos($line, ':') === false) continue;
            [$k, $v] = explode(':', $line, 2);
            $respHeaders[strtolower(trim($k))] = trim($v);
        }

        $json = json_decode($rawBody, true);

        return [
            'status'   => $status,
            'headers'  => $respHeaders,
            'body'     => $rawBody,
            'json'     => is_array($json) ? $json : null,
            'time_ms'  => $timeMs,
            'error'    => null,
        ];
    }

    public function get(string $path, array $headers = []): array
    {
        return $this->request('GET', $path, null, $headers);
    }

    public function postJson(string $path, array $body, array $headers = [], bool $withCsrf = true): array
    {
        return $this->request('POST', $path, $body, $headers, $withCsrf);
    }

    /** GET an HTML page and extract the CSRF_TOKEN injected in a <script> (index.php / admin.php) */
    public function fetchCsrfFromHtml(string $path): ?string
    {
        $res = $this->get($path);
        if (preg_match('/CSRF_TOKEN\s*=\s*(?:\'([a-f0-9]*)\'|"([a-f0-9]*)")/', $res['body'], $m)) {
            $token = $m[1] !== '' ? $m[1] : ($m[2] ?? '');
            return $token !== '' ? $token : null;
        }
        return null;
    }

    /** multipart/form-data upload (for image upload tests) */
    public function uploadFile(string $path, string $fieldName, string $filePath, string $mimeType, string $fileNameOverride = ''): array
    {
        $url = $this->baseUrl . $path;
        $ch  = curl_init($url);

        $name = $fileNameOverride !== '' ? $fileNameOverride : basename($filePath);
        $cfile = new CURLFile($filePath, $mimeType, $name);

        $hdrs = ['Accept: application/json'];
        if ($this->csrfToken !== null) $hdrs[] = 'X-CSRF-Token: ' . $this->csrfToken;

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => [$fieldName => $cfile],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_COOKIEJAR      => $this->cookieFile,
            CURLOPT_COOKIEFILE     => $this->cookieFile,
            CURLOPT_HTTPHEADER     => $hdrs,
            CURLOPT_TIMEOUT        => 30,
        ]);

        $t0 = microtime(true);
        $raw = curl_exec($ch);
        $timeMs = (microtime(true) - $t0) * 1000;
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $rawBody = $raw === false ? '' : substr($raw, $headerSize);
        $json = json_decode($rawBody, true);

        return ['status' => $status, 'body' => $rawBody, 'json' => is_array($json) ? $json : null, 'time_ms' => $timeMs];
    }

    /** login helper: log in + automatically fetch the CSRF token from HTML */
    public function loginAs(string $username, string $password, string $csrfPagePath = '/'): array
    {
        $this->resetCookies();
        $res = $this->postJson('/api.php?action=login', ['username' => $username, 'password' => $password], [], false);
        if (($res['json']['ok'] ?? false) === true) {
            $token = $this->fetchCsrfFromHtml($csrfPagePath);
            $this->setCsrfToken($token);
        }
        return $res;
    }
}
