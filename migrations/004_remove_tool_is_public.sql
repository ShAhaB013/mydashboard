-- 004_remove_tool_is_public.sql
-- Removes tools.is_public. Cards no longer have a "make everyone see this"
-- switch — visibility comes only from tool_access (direct grant) or
-- category_access (grant on the card's category), the same two-level grant
-- system already used to target notifications. Backfill first so no user
-- loses access to a card they could already see.
-- Admins excluded from the backfill: they already see every card via
-- ToolModel::all() regardless of tool_access.
--
-- IMPORTANT: the tool_access INSERT below is raw SQL, not routed through
-- AccessModel::setAll(), so it does NOT trigger NotificationModel's incremental
-- notification_recipients refresh. Confirmed necessary in local testing: running
-- just the tool_access backfill left 6 rows of drift in notification_recipients
-- (php migrations/rebuild_notification_recipients.php --check caught it). The
-- production host has no CLI to run that rebuild script, so the second INSERT
-- below reproduces its tool_access branch as plain SQL (same logic as the
-- migration 003 backfill) to keep notification_recipients in sync inline here.

INSERT IGNORE INTO tool_access (user_id, tool_id)
SELECT u.id, t.id FROM tools t JOIN users u ON u.role = 'user' WHERE t.is_public = 1;

INSERT IGNORE INTO notification_recipients (notification_id, user_id, created_at)
SELECT n.id, ta.user_id, n.created_at
FROM notifications n
JOIN notification_badges nb ON nb.notification_id = n.id
JOIN tools t ON t.category_id = nb.category_id
JOIN tool_access ta ON ta.tool_id = t.id
WHERE n.target_all_users = 0;

ALTER TABLE tools DROP INDEX idx_tools_is_public;
ALTER TABLE tools DROP COLUMN is_public;
