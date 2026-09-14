<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// EmailLogController — admin API for the email log page (list/delete/clear)
// ═══════════════════════════════════════════════════════════

class EmailLogController
{
    private EmailLogModel $model;
    private Request       $request;

    public function __construct(EmailLogModel $model, Request $request)
    {
        $this->model   = $model;
        $this->request = $request;
    }

    public function list(): void
    {
        $page     = max(1, $this->request->inputInt('page', 1));
        $perPage  = max(1, min(100, $this->request->inputInt('per_page', 20)));
        $status   = $this->request->input('status');
        $status   = $status !== '' ? $status : null;
        $search   = $this->request->input('search');
        $dateFrom = $this->request->input('date_from');
        $dateTo   = $this->request->input('date_to');
        $sortDir  = $this->request->input('sort_dir', 'desc');
        if (!in_array($sortDir, ['asc', 'desc'], true)) $sortDir = 'desc';

        $total     = $this->model->countAll($status, $search, $dateFrom, $dateTo);
        $pageCount = (int) max(1, (int) ceil($total / $perPage));
        $rows      = $this->model->allPaginated($page, $perPage, $status, $search, $dateFrom, $dateTo, $sortDir);

        Response::ok([
            'logs'          => array_map([EmailLogModel::class, 'toFrontend'], $rows),
            'status_counts' => $this->model->countByStatus($search, $dateFrom, $dateTo),
            'total_logs'    => $this->model->countAll(),
            'pagination'    => [
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
        $status = $this->request->input('status');
        $status = $status !== '' ? $status : null;

        Response::ok(['deleted' => $this->model->clearAll($status)]);
    }
}
