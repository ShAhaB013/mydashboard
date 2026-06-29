<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// AccessController — هندل کردن API دسترسی دو سطحی
// ═══════════════════════════════════════════════════════════

class AccessController
{
    private AccessModel $model;
    private Request     $request;

    public function __construct(AccessModel $model, Request $request)
    {
        $this->model   = $model;
        $this->request = $request;
    }

    /** دریافت دسترسی‌های یک کاربر (هر دو سطح) */
    public function get(): void
    {
        $userId = $this->request->inputInt('user_id');

        if ($userId <= 0) {
            Response::error('شناسه کاربر نامعتبر است');
            return;
        }

        Response::ok($this->model->getAll($userId));
    }

    /** ذخیره دسترسی‌های یک کاربر (هر دو سطح با هم) */
    public function set(): void
    {
        $userId  = $this->request->inputInt('user_id');
        $toolIds = $this->request->inputArray('tool_ids');
        $badges  = $this->request->inputArray('badges');

        if ($userId <= 0) {
            Response::error('شناسه کاربر نامعتبر است');
            return;
        }

        // اطمینان از integer بودن tool_ids
        $toolIds = array_map('intval', $toolIds);
        $toolIds = array_filter($toolIds, fn($id) => $id > 0);

        // اطمینان از string بودن badges
        $badges = array_filter(
            array_map('strval', $badges),
            fn($b) => $b !== ''
        );

        $ok = $this->model->setAll($userId, array_values($toolIds), array_values($badges));

        $ok ? Response::ok() : Response::error('خطا در ذخیره دسترسی‌ها');
    }

    /** لیست badge های موجود در سیستم */
    public function listBadges(): void
    {
        Response::ok(['badges' => $this->model->getAvailableBadges()]);
    }
}
