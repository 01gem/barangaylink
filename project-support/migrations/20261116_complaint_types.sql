-- Complaint revision Phase 1: dynamic complaint types (schema + seed + backfill).
-- Additive. Back up the database and test on a disposable copy first.
-- Safe to re-run: tables use IF NOT EXISTS, every ALTER is guarded through
-- information_schema, seeds never overwrite an existing row, and the backfill
-- only touches rows whose complaint_type_id is still NULL.
-- complaints.category is NOT changed: it stays as the historical name.
-- This file never selects a database.

-- ---------------------------------------------------------------------------
-- complaint_types
-- type_code is immutable after creation (enforced in application code, Phase 3).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `complaint_types` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `type_code` VARCHAR(50) NOT NULL,
  `type_name` VARCHAR(100) NOT NULL,
  `description` VARCHAR(255) NULL DEFAULT NULL,
  `respondent_mode` ENUM('none','optional','required') NOT NULL DEFAULT 'none',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  `version` INT NOT NULL DEFAULT 1,
  `created_by` INT NULL DEFAULT NULL,
  `updated_by` INT NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_complaint_type_code` (`type_code`),
  UNIQUE KEY `uq_complaint_type_name` (`type_name`),
  KEY `idx_type_active` (`is_active`, `sort_order`),
  CONSTRAINT `fk_complaint_types_created_by` FOREIGN KEY (`created_by`)
    REFERENCES `barangay_officials`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_complaint_types_updated_by` FOREIGN KEY (`updated_by`)
    REFERENCES `barangay_officials`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- complaint_type_fields
-- field_key must match ^[a-z][a-z0-9_]{0,49}$ (enforced in application code).
-- options_json (select only), in display order:
--   [{"value":"pothole","label":"Pothole","is_active":1}, ...]
-- No cascade: a type that has fields cannot be deleted.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `complaint_type_fields` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `complaint_type_id` INT NOT NULL,
  `field_key` VARCHAR(50) NOT NULL,
  `label` VARCHAR(100) NOT NULL,
  `field_type` ENUM('select','text','number','date') NOT NULL,
  `is_required` TINYINT(1) NOT NULL DEFAULT 0,
  `options_json` JSON NULL DEFAULT NULL,
  `max_length` INT NULL DEFAULT NULL,
  `min_value` DECIMAL(12,2) NULL DEFAULT NULL,
  `max_value` DECIMAL(12,2) NULL DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  `version` INT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_type_field` (`complaint_type_id`, `field_key`),
  KEY `idx_type_fields` (`complaint_type_id`, `is_active`, `sort_order`),
  CONSTRAINT `fk_type_field_type` FOREIGN KEY (`complaint_type_id`)
    REFERENCES `complaint_types`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- complaints.complaint_type_id (column, index, foreign key)
-- ---------------------------------------------------------------------------
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE() AND table_name = 'complaints' AND column_name = 'complaint_type_id') = 0,
  'ALTER TABLE complaints ADD COLUMN complaint_type_id INT NULL DEFAULT NULL AFTER category',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.statistics
                WHERE table_schema = DATABASE() AND table_name = 'complaints' AND index_name = 'idx_complaint_type') = 0,
  'ALTER TABLE complaints ADD KEY idx_complaint_type (complaint_type_id)',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.table_constraints
                WHERE constraint_schema = DATABASE() AND table_name = 'complaints'
                  AND constraint_name = 'fk_complaint_type' AND constraint_type = 'FOREIGN KEY') = 0,
  'ALTER TABLE complaints ADD CONSTRAINT fk_complaint_type FOREIGN KEY (complaint_type_id) REFERENCES complaint_types(id) ON DELETE RESTRICT',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- Seed types. type_name equals the keys of complaint_extra_fields_schema()
-- character for character. An existing row (same type_code) is left untouched.
-- ---------------------------------------------------------------------------
INSERT INTO `complaint_types` (`type_code`, `type_name`, `respondent_mode`, `sort_order`) VALUES
  ('road_infrastructure', 'Road / Infrastructure',      'none',     10),
  ('noise_disturbance',   'Noise / Public Disturbance', 'optional', 20),
  ('flooding_drainage',   'Flooding / Drainage',        'none',     30),
  ('public_safety',       'Public Safety',              'optional', 40),
  ('other',               'Other',                      'optional', 50)
ON DUPLICATE KEY UPDATE `type_code` = `type_code`;

-- ---------------------------------------------------------------------------
-- Seed fields. Keys, labels, option values, option labels and option order are
-- copied from api/complaints/_extra_fields.php. An existing row (same type and
-- field_key) is left untouched.
-- ---------------------------------------------------------------------------
INSERT INTO `complaint_type_fields`
  (`complaint_type_id`, `field_key`, `label`, `field_type`, `is_required`, `options_json`, `sort_order`)
SELECT t.`id`, f.`field_key`, f.`label`, 'select', 0, f.`options_json`, f.`sort_order`
FROM (
  SELECT 'road_infrastructure' AS `type_code`, 'road_issue' AS `field_key`, 'Infrastructure issue' AS `label`, 10 AS `sort_order`,
    '[{"value":"pothole","label":"Pothole","is_active":1},{"value":"damaged_road","label":"Damaged road","is_active":1},{"value":"streetlight","label":"Streetlight","is_active":1},{"value":"sidewalk","label":"Sidewalk","is_active":1},{"value":"other","label":"Other","is_active":1}]' AS `options_json`
  UNION ALL SELECT 'road_infrastructure', 'access_impact', 'Access impact', 20,
    '[{"value":"passable","label":"Passable","is_active":1},{"value":"partly_blocked","label":"Partly blocked","is_active":1},{"value":"blocked","label":"Blocked","is_active":1}]'
  UNION ALL SELECT 'noise_disturbance', 'noise_source', 'Noise source', 10,
    '[{"value":"music","label":"Music","is_active":1},{"value":"construction","label":"Construction","is_active":1},{"value":"vehicles","label":"Vehicles","is_active":1},{"value":"gathering","label":"Gathering","is_active":1},{"value":"other","label":"Other","is_active":1}]'
  UNION ALL SELECT 'noise_disturbance', 'usual_time', 'Usual time', 20,
    '[{"value":"daytime","label":"Daytime","is_active":1},{"value":"evening","label":"Evening","is_active":1},{"value":"overnight","label":"Overnight","is_active":1},{"value":"varies","label":"Varies","is_active":1}]'
  UNION ALL SELECT 'flooding_drainage', 'drainage_issue', 'Drainage issue', 10,
    '[{"value":"blocked_drain","label":"Blocked drain","is_active":1},{"value":"flooding","label":"Flooding","is_active":1},{"value":"standing_water","label":"Standing water","is_active":1},{"value":"other","label":"Other","is_active":1}]'
  UNION ALL SELECT 'flooding_drainage', 'affected_area', 'Affected area', 20,
    '[{"value":"one_property","label":"One property","is_active":1},{"value":"street","label":"Street","is_active":1},{"value":"several_streets","label":"Several streets","is_active":1}]'
  UNION ALL SELECT 'public_safety', 'safety_hazard', 'Safety concern', 10,
    '[{"value":"poor_lighting","label":"Poor lighting","is_active":1},{"value":"unsafe_structure","label":"Unsafe structure","is_active":1},{"value":"blocked_path","label":"Blocked path","is_active":1},{"value":"other","label":"Other","is_active":1}]'
  UNION ALL SELECT 'public_safety', 'ongoing', 'Is the concern still present?', 20,
    '[{"value":"yes","label":"Yes","is_active":1},{"value":"no","label":"No","is_active":1},{"value":"unsure","label":"Unsure","is_active":1}]'
) AS f
JOIN `complaint_types` t ON t.`type_code` = f.`type_code`
ON DUPLICATE KEY UPDATE `field_key` = VALUES(`field_key`);

-- "Other": one required free-text field. Not in the PHP schema.
-- Existing Other complaints get no value for it (they predate the field).
INSERT INTO `complaint_type_fields`
  (`complaint_type_id`, `field_key`, `label`, `field_type`, `is_required`, `max_length`, `sort_order`)
SELECT t.`id`, 'specify', 'Please specify', 'text', 1, 150, 10
FROM `complaint_types` t
WHERE t.`type_code` = 'other'
ON DUPLICATE KEY UPDATE `field_key` = VALUES(`field_key`);

-- ---------------------------------------------------------------------------
-- Backfill complaints.complaint_type_id. Byte-exact name match, then every
-- row still unmatched goes to "Other". category is left as stored.
-- ---------------------------------------------------------------------------
UPDATE `complaints` c
JOIN `complaint_types` t ON CAST(t.`type_name` AS BINARY) = CAST(c.`category` AS BINARY)
SET c.`complaint_type_id` = t.`id`
WHERE c.`complaint_type_id` IS NULL;

UPDATE `complaints`
SET `complaint_type_id` = (SELECT `id` FROM `complaint_types` WHERE `type_code` = 'other')
WHERE `complaint_type_id` IS NULL;
