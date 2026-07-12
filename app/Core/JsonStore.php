<?php
// ═══════════════════════════════════════════════════════════
// JsonStore — reads/writes JSON files (file-based storage for icons/animations)
// Previous name: Database (was confused with DB — the MySQL connection)
// ═══════════════════════════════════════════════════════════

class JsonStore
{
    private string $filePath;

    public function __construct(string $filePath)
    {
        $this->filePath = $filePath;
    }

    /** Reads all records */
    public function all(): array
    {
        if (!file_exists($this->filePath)) {
            return [];
        }

        $data = json_decode(file_get_contents($this->filePath), true);
        return is_array($data) ? $data : [];
    }

    /** Saves all records — atomic write + file lock */
    public function save(array $data): bool
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $tmp  = $this->filePath . '.tmp';

        // Write to a temp file with an exclusive lock
        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            return false;
        }

        // Atomic replace — if rename fails, the original file stays intact
        return rename($tmp, $this->filePath);
    }
}
