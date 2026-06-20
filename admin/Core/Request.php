<?php
// ═══════════════════════════════════════════════════════════
// Request — پارس کردن ورودی‌های HTTP
// ═══════════════════════════════════════════════════════════

class Request
{
    private array $body;
    private array $query;

    public function __construct()
    {
        $rawInput    = file_get_contents('php://input');
        $this->body  = json_decode($rawInput, true) ?? [];
        $this->query = $_GET;
    }

    /** دریافت پارامتر از query string */
    public function query(string $key, string $default = ''): string
    {
        return trim($this->query[$key] ?? $default);
    }

    /** دریافت فیلد از body */
    public function input(string $key, string $default = ''): string
    {
        return trim($this->body[$key] ?? $default);
    }

    /** دریافت فیلد عددی از body */
    public function inputInt(string $key, int $default = -1): int
    {
        return isset($this->body[$key]) ? intval($this->body[$key]) : $default;
    }

    /** دریافت آرایه از body */
    public function inputArray(string $key): array
    {
        $value = $this->body[$key] ?? [];
        return is_array($value) ? $value : [];
    }

    /** متد HTTP درخواست */
    public function method(): string
    {
        return $_SERVER['REQUEST_METHOD'] ?? 'GET';
    }

    /** آیا درخواست POST است؟ */
    public function isPost(): bool
    {
        return $this->method() === 'POST';
    }

    /** دریافت فیلد POST فرم */
    public function post(string $key, string $default = ''): string
    {
        return trim($_POST[$key] ?? $default);
    }
}
