<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// LogController — admin API for the error log viewer (list/delete/clear)
// ═══════════════════════════════════════════════════════════

class LogController
{
    private LogModel $model;
    private Request  $request;

    public function __construct(LogModel $model, Request $request)
    {
        $this->model   = $model;
        $this->request = $request;
    }

    public function list(): void
    {
        $page     = max(1, $this->request->inputInt('page', 1));
        $perPage  = max(1, min(100, $this->request->inputInt('per_page', 20)));
        $level    = $this->request->input('level');
        $level    = $level !== '' ? $level : null;
        $search   = $this->request->input('search');
        $dateFrom = $this->request->input('date_from');
        $dateTo   = $this->request->input('date_to');

        $sortBy = $this->request->input('sort_by', 'created_at');
        if (!in_array($sortBy, ['created_at', 'level'], true)) $sortBy = 'created_at';
        $sortDir = $this->request->input('sort_dir', 'desc');
        if (!in_array($sortDir, ['asc', 'desc'], true)) $sortDir = 'desc';

        $total     = $this->model->countAll($level, $search, $dateFrom, $dateTo);
        $pageCount = (int) max(1, (int) ceil($total / $perPage));
        $rows      = $this->model->allPaginated($page, $perPage, $level, $search, $dateFrom, $dateTo, $sortBy, $sortDir);

        Response::ok([
            'logs'         => array_map([LogModel::class, 'toFrontend'], $rows),
            'level_counts' => $this->model->countByLevel($search, $dateFrom, $dateTo),
            'total_logs'   => $this->model->countAll(),
            'pagination'   => [
                'page'       => $page,
                'per_page'   => $perPage,
                'total'      => $total,
                'page_count' => $pageCount,
            ],
        ]);
    }

    public function delete(): void
    {
        $id = $this->request->inputInt('id');
        if ($id <= 0) { Response::error('شناسه لاگ نامعتبر است'); return; }

        if (!$this->model->findById($id)) { Response::error('لاگ یافت نشد'); return; }

        $this->model->delete($id);
        Response::ok();
    }

    public function clear(): void
    {
        if ($this->request->inputInt('confirm', 0) !== 1) {
            Response::error('عملیات تایید نشده است');
            return;
        }
        $level = $this->request->input('level');
        $level = $level !== '' ? $level : null;

        $deleted = $this->model->clearAll($level);
        Response::ok(['deleted' => $deleted]);
    }
}
