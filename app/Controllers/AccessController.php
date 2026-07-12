<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════
// AccessController — handles the two-level access API
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

    /** Get a user's access (both levels) */
    public function get(): void
    {
        $userId = $this->request->inputInt('user_id');

        if ($userId <= 0) {
            Response::error('شناسه کاربر نامعتبر است');
            return;
        }

        Response::ok($this->model->getAll($userId));
    }

    /** Save a user's access (both levels together) */
    public function set(): void
    {
        $userId  = $this->request->inputInt('user_id');
        $toolIds = $this->request->inputArray('tool_ids');
        $badges  = $this->request->inputArray('badges');

        if ($userId <= 0) {
            Response::error('شناسه کاربر نامعتبر است');
            return;
        }

        $toolIds = array_map('intval', $toolIds);
        $toolIds = array_filter($toolIds, fn($id) => $id > 0);

        $badges = array_filter(
            array_map('strval', $badges),
            fn($b) => $b !== ''
        );

        $ok = $this->model->setAll($userId, array_values($toolIds), array_values($badges));

        $ok ? Response::ok() : Response::error('خطا در ذخیره دسترسی‌ها');
    }

    public function listBadges(): void
    {
        Response::ok(['badges' => $this->model->getAvailableBadges()]);
    }
}
