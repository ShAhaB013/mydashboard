<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// AccessRoleController — admin API for access roles (list/save/delete)
// ═══════════════════════════════════════════════════════════

class AccessRoleController
{
    private const NAME_MAX = 60;
    private const DESC_MAX = 255;

    private AccessRoleModel $model;
    private Request         $request;

    public function __construct(AccessRoleModel $model, Request $request)
    {
        $this->model   = $model;
        $this->request = $request;
    }

    /** Roles with members + grants, plus the category names that can be granted */
    public function list(): void
    {
        Response::ok([
            'roles'  => $this->model->allWithSummary(),
            'badges' => (new CategoryModel())->namesInUseByTools(),
        ]);
    }

    /** Create (id=0) or update a role */
    public function save(): void
    {
        $id          = $this->request->inputInt('id');
        $name        = trim((string) $this->request->input('name'));
        $description = trim((string) $this->request->input('description'));
        $badges      = array_values(array_filter(array_map('strval', $this->request->inputArray('badges')), fn($b) => $b !== ''));
        $toolIds     = array_map('intval', $this->request->inputArray('tool_ids'));
        $hiddenMenus = array_map('strval', $this->request->inputArray('hidden_menus'));

        if ($name === '') {
            Response::error('نام نقش الزامی است', 'name');
            return;
        }
        if (mb_strlen($name) > self::NAME_MAX) {
            Response::error('نام نقش حداکثر ' . self::NAME_MAX . ' کاراکتر است', 'name');
            return;
        }
        if (mb_strlen($description) > self::DESC_MAX) {
            Response::error('توضیحات حداکثر ' . self::DESC_MAX . ' کاراکتر است', 'description');
            return;
        }
        if ($id > 0 && !$this->model->exists($id)) {
            Response::error('نقش یافت نشد');
            return;
        }
        if ($this->model->nameExists($name, $id)) {
            Response::error('نقشی با این نام وجود دارد', 'name');
            return;
        }

        $savedId = $this->model->save($id, $name, $description, $badges, $toolIds, $hiddenMenus);
        Response::ok(['id' => $savedId]);
    }

    public function delete(): void
    {
        $id = $this->request->inputInt('id');
        if (!$this->model->exists($id)) {
            Response::error('نقش یافت نشد');
            return;
        }
        $members = $this->model->memberCount($id);
        if ($members > 0) {
            Response::error("این نقش {$members} کاربر دارد؛ ابتدا نقش آن‌ها را تغییر دهید");
            return;
        }
        $this->model->delete($id);
        Response::ok();
    }
}
