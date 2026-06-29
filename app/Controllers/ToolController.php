<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// ToolController — هندل کردن API ابزارها
// ═══════════════════════════════════════════════════════════

class ToolController
{
    private ToolModel $model;
    private Request   $request;

    public function __construct(ToolModel $model, Request $request)
    {
        $this->model   = $model;
        $this->request = $request;
    }

    /** لیست صفحه‌بندی‌شده ابزارها برای پنل ادمین (سمت سرور) */
    public function listPaginated(): void
    {
        $page    = max(1, $this->request->inputInt('page', 1));
        $perPage = $this->request->inputInt('per_page', 20);
        $perPage = max(1, min(100, $perPage));
        $search  = $this->request->input('search');

        $total = $this->model->countForAdmin($search);
        $rows  = $this->model->allForAdminPaginated($page, $perPage, $search);

        $pageCount = (int) max(1, (int) ceil($total / $perPage));

        Response::ok([
            'tools'      => ToolModel::toFrontend($rows),
            'pagination' => [
                'page'       => $page,
                'per_page'   => $perPage,
                'total'      => $total,
                'page_count' => $pageCount,
            ],
        ]);
    }

    /** افزودن ابزار جدید */
    public function add(): void
    {
        $data = $this->extractToolData();

        if (!$this->validateToolData($data)) return;

        $this->model->create($data)
            ? Response::ok()
            : Response::error('خطا در ذخیره ابزار');
    }

    /** ویرایش ابزار (مبتنی بر id) */
    public function edit(): void
    {
        $id   = $this->request->inputInt('id');
        $data = $this->extractToolData();

        if (!$this->validateToolData($data)) return;

        if ($id <= 0 || !$this->model->findById($id)) {
            Response::error('ابزار یافت نشد');
            return;
        }

        $this->model->updateById($id, $data)
            ? Response::ok()
            : Response::error('خطا در ویرایش ابزار');
    }

    /** حذف ابزار (مبتنی بر id) */
    public function delete(): void
    {
        $id = $this->request->inputInt('id');

        if ($id <= 0 || !$this->model->findById($id)) {
            Response::error('ابزار یافت نشد');
            return;
        }

        $this->model->deleteById($id)
            ? Response::ok()
            : Response::error('خطا در حذف ابزار');
    }


    /** مرتب‌سازی مجدد سراسری (آرایه کامل id ها) */
    public function reorder(): void
    {
        $ids = $this->request->inputArray('ids');

        if (empty($ids)) {
            Response::error('ترتیب نامعتبر است');
            return;
        }

        $this->model->reorderByIds($ids)
            ? Response::ok()
            : Response::error('ذخیره ترتیب ناموفق بود (لیست کامل نیست)');
    }

    /** تغییر وضعیت عمومی/خصوصی ابزار */
    public function togglePublic(): void
    {
        $id = $this->request->inputInt('id');

        if ($id <= 0) {
            Response::error('شناسه ابزار نامعتبر است');
            return;
        }

        $this->model->togglePublic($id)
            ? Response::ok()
            : Response::error('خطا در تغییر وضعیت');
    }

    // ── Private Helpers ──────────────────────────────────────

    private function extractToolData(): array
    {
        return [
            'title'       => $this->request->input('title'),
            'description' => $this->request->input('description'),
            'path'        => $this->request->input('path'),
            'badge'       => $this->request->input('badge'),
            'iconKey'     => $this->request->input('iconKey', 'star'),
            'deco'        => $this->request->input('deco', 'generic'),
            'accentColor' => $this->request->input('accentColor'),
        ];
    }

    private function validateToolData(array $data): bool
    {
        if (empty($data['title'])) {
            Response::error('عنوان الزامی است');
            return false;
        }

        if (!Validator::isValidPath($data['path'])) {
            Response::error('مسیر نامعتبر است');
            return false;
        }

        return true;
    }
}