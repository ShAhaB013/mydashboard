<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// LogModel — reads/deletes rows from the error_logs table (written by Logger)
// ═══════════════════════════════════════════════════════════

class LogModel
{
    private const LEVELS = ['debug', 'info', 'warning', 'error'];

    // Severity order (low → high) used for "sort by level" — the ENUM's own
    // declaration order (debug,info,warning,error) already matches this, but
    // FIELD() is spelled out explicitly so it doesn't silently depend on that.
    private const LEVEL_SEVERITY_SQL = "FIELD(level,'debug','info','warning','error')";

    private function normalizeLevel(string $level): ?string
    {
        return in_array($level, self::LEVELS, true) ? $level : null;
    }

    /** Builds the "WHERE level = :level AND message LIKE :search AND created_at BETWEEN ..." clause. */
    private function buildFilter(?string $level, string $search, string $dateFrom, string $dateTo, array &$params): string
    {
        $sql   = '';
        $level = $level !== null ? $this->normalizeLevel($level) : null;
        if ($level !== null) {
            $sql .= ' AND level = :level';
            $params[':level'] = $level;
        }
        $search = trim($search);
        if ($search !== '') {
            $sql .= ' AND message LIKE :search';
            $params[':search'] = '%' . $search . '%';
        }
        $dateFrom = trim($dateFrom);
        $dateTo   = trim($dateTo);
        if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
            $sql .= ' AND created_at >= :date_from';
            $params[':date_from'] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            $sql .= ' AND created_at <= :date_to';
            $params[':date_to'] = $dateTo . ' 23:59:59';
        }
        return $sql;
    }

    /** Builds a validated ORDER BY clause — only 'created_at'/'level' × 'asc'/'desc' are accepted. */
    private function buildOrder(string $sortBy, string $sortDir): string
    {
        $dir = strtolower($sortDir) === 'asc' ? 'ASC' : 'DESC';
        if ($sortBy === 'level') {
            return self::LEVEL_SEVERITY_SQL . " {$dir}, created_at DESC, id DESC";
        }
        return "created_at {$dir}, id {$dir}";
    }

    public function countAll(?string $level = null, string $search = '', string $dateFrom = '', string $dateTo = ''): int
    {
        $params = [];
        $filter = $this->buildFilter($level, $search, $dateFrom, $dateTo, $params);
        return (int) DB::run("SELECT COUNT(*) FROM error_logs WHERE 1=1{$filter}", $params)->fetchColumn();
    }

    /** Row count per level (ignores the level filter itself, but respects search/date range) — feeds the filter chips. */
    public function countByLevel(string $search = '', string $dateFrom = '', string $dateTo = ''): array
    {
        $params = [];
        $filter = $this->buildFilter(null, $search, $dateFrom, $dateTo, $params);
        $rows = DB::run(
            "SELECT level, COUNT(*) AS c FROM error_logs WHERE 1=1{$filter} GROUP BY level",
            $params
        )->fetchAll();

        $counts = array_fill_keys(self::LEVELS, 0);
        foreach ($rows as $r) {
            $counts[$r['level']] = (int) $r['c'];
        }
        return $counts;
    }

    /**
     * Admin list, plain page-number pagination (low-traffic admin table).
     * $sortBy: 'created_at' (default) | 'level'. $sortDir: 'desc' (default) | 'asc'.
     */
    public function allPaginated(
        int $page,
        int $perPage,
        ?string $level = null,
        string $search = '',
        string $dateFrom = '',
        string $dateTo = '',
        string $sortBy = 'created_at',
        string $sortDir = 'desc'
    ): array {
        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset  = ($page - 1) * $perPage;

        $params = [];
        $filter = $this->buildFilter($level, $search, $dateFrom, $dateTo, $params);
        $order  = $this->buildOrder($sortBy, $sortDir);

        return DB::run(
            "SELECT * FROM error_logs WHERE 1=1{$filter} ORDER BY {$order} LIMIT {$perPage} OFFSET {$offset}",
            $params
        )->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $row = DB::run('SELECT * FROM error_logs WHERE id = :id', [':id' => $id])->fetch();
        return $row ?: null;
    }

    public function delete(int $id): bool
    {
        DB::run('DELETE FROM error_logs WHERE id = :id', [':id' => $id]);
        return true;
    }

    /** Deletes all logs, or all logs of one level if $level is given. Returns rows affected. */
    public function clearAll(?string $level = null): int
    {
        $level = $level !== null ? $this->normalizeLevel($level) : null;
        if ($level !== null) {
            $stmt = DB::run('DELETE FROM error_logs WHERE level = :level', [':level' => $level]);
        } else {
            $stmt = DB::run('DELETE FROM error_logs');
        }
        return $stmt->rowCount();
    }

    public static function toFrontend(array $row): array
    {
        return [
            'id'         => (int) $row['id'],
            'level'      => $row['level'],
            'message'    => $row['message'],
            'context'    => $row['context'] !== null ? json_decode((string) $row['context'], true) : null,
            'created_at' => $row['created_at'],
        ];
    }
}
