<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// CategoryController — category management page (list/rename/delete)
// ═══════════════════════════════════════════════════════════

class CategoryController
{
    private CategoryModel $model;
    private Request       $request;

    public function __construct(CategoryModel $model, Request $request)
    {
        $this->model   = $model;
        $this->request = $request;
    }

    public function list(): void
    {
        Response::ok(['categories' => $this->model->allWithCounts()]);
    }

    public function rename(): void
    {
        $id   = $this->request->inputInt('id');
        $name = trim((string) $this->request->input('name'));

        if ($id <= 0) {
            Response::error('دسته‌بندی نامعتبر است');
            return;
        }

        $err = Validator::categoryName($name);
        if ($err !== '') {
            Response::error($err, 'name');
            return;
        }

        if (!$this->model->rename($id, $name)) {
            Response::error('این نام قبلاً برای دسته‌بندی دیگری استفاده شده است', 'name');
            return;
        }

        Response::ok();
    }

    public function delete(): void
    {
        $id = $this->request->inputInt('id');
        if ($id <= 0) {
            Response::error('دسته‌بندی نامعتبر است');
            return;
        }

        if (!$this->model->delete($id)) {
            Response::error('این دسته‌بندی هنوز به یک یا چند ابزار متصل است؛ ابتدا دسته‌بندی آن ابزارها را تغییر دهید');
            return;
        }

        Response::ok();
    }
}
