-- 001_categories_normalization.sql
-- Introduces a real `categories` table and migrates tools.badge,
-- notification_badges.badge, category_access.badge to category_id FKs.

CREATE TABLE IF NOT EXISTS categories (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_categories_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed from every distinct non-empty badge string across all 3 source tables (not just
-- tools) so an already-orphaned category_access/notification_badges string isn't dropped.
INSERT IGNORE INTO categories (name) SELECT DISTINCT badge FROM tools WHERE badge != '';
INSERT IGNORE INTO categories (name) SELECT DISTINCT badge FROM notification_badges WHERE badge != '';
INSERT IGNORE INTO categories (name) SELECT DISTINCT badge FROM category_access WHERE badge != '';

-- tools.badge -> tools.category_id (nullable: an uncategorized tool is valid)
ALTER TABLE tools ADD COLUMN category_id INT UNSIGNED NULL AFTER badge;
UPDATE tools t JOIN categories c ON c.name = t.badge SET t.category_id = c.id WHERE t.badge != '';
ALTER TABLE tools DROP INDEX idx_tools_badge;
ALTER TABLE tools DROP COLUMN badge;
ALTER TABLE tools ADD KEY idx_tools_category (category_id);
ALTER TABLE tools ADD CONSTRAINT tools_category_fk FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL;

-- notification_badges.badge -> notification_badges.category_id (PK column, NOT NULL)
-- The PK/index-drop/column-drop/PK-add are combined into one ALTER TABLE statement:
-- notification_id has an incoming FK (nb_notif_fk) that requires a supporting index at
-- all times, and dropping the old PK as a separate statement leaves a window with no
-- index covering notification_id, which InnoDB rejects (errno 150, "rename" of the
-- temp table fails). A single multi-clause ALTER TABLE is evaluated against the final
-- schema only, so no such window exists.
ALTER TABLE notification_badges ADD COLUMN category_id INT UNSIGNED NULL AFTER badge;
UPDATE notification_badges nb JOIN categories c ON c.name = nb.badge SET nb.category_id = c.id;
ALTER TABLE notification_badges
  MODIFY COLUMN category_id INT UNSIGNED NOT NULL,
  DROP PRIMARY KEY,
  DROP INDEX idx_badge,
  DROP COLUMN badge,
  ADD PRIMARY KEY (notification_id, category_id),
  ADD KEY idx_nb_category (category_id, notification_id);
ALTER TABLE notification_badges ADD CONSTRAINT nb_category_fk FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE;

-- category_access.badge -> category_access.category_id (PK column, NOT NULL)
-- Same reasoning: combine PK-drop/column-drop/PK-add into one ALTER TABLE statement.
ALTER TABLE category_access ADD COLUMN category_id INT UNSIGNED NULL AFTER badge;
UPDATE category_access ca JOIN categories c ON c.name = ca.badge SET ca.category_id = c.id;
ALTER TABLE category_access
  MODIFY COLUMN category_id INT UNSIGNED NOT NULL,
  DROP PRIMARY KEY,
  DROP COLUMN badge,
  ADD PRIMARY KEY (user_id, category_id);
ALTER TABLE category_access ADD CONSTRAINT category_access_category_fk FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE;
