-- ═══════════════════════════════════════════════════════════
-- بهینه‌سازی ایندکس‌های دیتابیس (اجرای دستی روی MySQL 8)
-- این فایل migration خودکار ندارد؛ باید یک‌بار دستی از طریق
-- phpMyAdmin یا mysql CLI روی دیتابیس محلی/سرور اجرا شود.
-- اجرای دوباره بی‌خطر است (IF NOT EXISTS روی هر ایندکس).
-- ═══════════════════════════════════════════════════════════

-- users: پشتیبانی از لاگین (username + is_active) و شمارش ادمین‌های فعال (role + is_active)
ALTER TABLE `users`
  ADD INDEX IF NOT EXISTS `idx_active` (`is_active`),
  ADD INDEX IF NOT EXISTS `idx_role_active` (`role`, `is_active`);

-- notifications: این کوئری (فیلتر is_public/target_all_users + مرتب‌سازی created_at)
-- روی تقریبا هر بارگذاری صفحه اجرا می‌شود (زنگ اعلان‌ها) — بالاترین اولویت
ALTER TABLE `notifications`
  ADD INDEX IF NOT EXISTS `idx_target_all` (`target_all_users`),
  ADD INDEX IF NOT EXISTS `idx_pub_target_created` (`is_public`, `target_all_users`, `created_at` DESC, `id` DESC);

-- notification_badges / category_access: پشتیبانی از JOIN معکوس بین این دو جدول بر اساس badge
ALTER TABLE `notification_badges`
  ADD INDEX IF NOT EXISTS `idx_badge` (`badge`);

ALTER TABLE `category_access`
  ADD INDEX IF NOT EXISTS `idx_badge` (`badge`);

-- sessions: پشتیبانی از ORDER BY last_seen در SessionModel::active()
ALTER TABLE `sessions`
  ADD INDEX IF NOT EXISTS `idx_last_seen` (`last_seen`);
