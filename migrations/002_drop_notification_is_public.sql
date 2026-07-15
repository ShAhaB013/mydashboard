-- 002_drop_notification_is_public.sql
-- Removes the notifications.is_public flag. It predates the auth refactor that
-- required login everywhere (guest browsing removed) — back then is_public=1 meant
-- "visible even to a logged-out guest", distinct from target_all_users=1. With no
-- guest path left, the two flags became functionally identical (both bypass category
-- restriction, visible to every logged-in user); keeping both was a dead, confusing
-- duplicate in the admin composer UI. Backfill first so no notification loses reach.

UPDATE notifications SET target_all_users = 1 WHERE is_public = 1 AND target_all_users = 0;

ALTER TABLE notifications DROP INDEX idx_pub_created;
ALTER TABLE notifications DROP COLUMN is_public;
