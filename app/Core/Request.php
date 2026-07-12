<?php
// ═══════════════════════════════════════════════════════════
// Request — parses HTTP inputs
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

    /** Gets a parameter from the query string */
    public function query(string $key, string $default = ''): string
    {
        return trim($this->query[$key] ?? $default);
    }

    /** Gets a field from the body */
    public function input(string $key, string $default = ''): string
    {
        return trim($this->body[$key] ?? $default);
    }

    /** Gets a numeric field from the body */
    public function inputInt(string $key, int $default = -1): int
    {
        return isset($this->body[$key]) ? intval($this->body[$key]) : $default;
    }

    /** Gets an array from the body */
    public function inputArray(string $key): array
    {
        $value = $this->body[$key] ?? [];
        return is_array($value) ? $value : [];
    }

    /** The request's HTTP method */
    public function method(): string
    {
        return $_SERVER['REQUEST_METHOD'] ?? 'GET';
    }

    /** Is this a POST request? */
    public function isPost(): bool
    {
        return $this->method() === 'POST';
    }

    /** Gets a form POST field */
    public function post(string $key, string $default = ''): string
    {
        return trim($_POST[$key] ?? $default);
    }
}
