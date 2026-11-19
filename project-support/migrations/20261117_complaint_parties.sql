-- Complaint revision Phase 5: complaint parties (complainants and respondents).
-- Additive. Back up the database and test on a disposable copy first.
-- Safe to re-run: the table uses IF NOT EXISTS, every ALTER is guarded through
-- information_schema, and the backfill skips complaints that already have a
-- complainant row.
-- This file never selects a database.

-- ---------------------------------------------------------------------------
-- complaint_parties
-- resident_id NULL = a person who is not registered (typed name/address/contact).
-- Registered parties read address and contact live from residents; the row
-- keeps only the name snapshot used on documents.
-- active_resident_id is resident_id while the row is active, else NULL, so a
-- removed registered party never blocks adding the same resident again.
-- fk_party_resident must stay ON DELETE RESTRICT: MySQL forbids CASCADE and
-- SET NULL on a base column of a stored generated column (active_resident_id).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `complaint_parties` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `complaint_id` INT NOT NULL,
  `role` ENUM('complainant','respondent') NOT NULL,
  `resident_id` INT NULL DEFAULT NULL,
  `full_name` VARCHAR(150) NOT NULL,
  `address` VARCHAR(255) NULL DEFAULT NULL,
  `contact_no` VARCHAR(20) NULL DEFAULT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `version` INT NOT NULL DEFAULT 1,
  `created_by_type` ENUM('resident','official','system') NOT NULL,
  `created_by_id` INT NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `active_resident_id` INT AS (IF(`is_active` = 1, `resident_id`, NULL)) STORED,
  PRIMARY KEY (`id`),
  KEY `idx_party_complaint` (`complaint_id`, `role`, `is_active`, `sort_order`),
  KEY `idx_party_resident` (`resident_id`),
  UNIQUE KEY `uq_party_resident` (`complaint_id`, `role`, `active_resident_id`),
  CONSTRAINT `fk_party_complaint` FOREIGN KEY (`complaint_id`)
    REFERENCES `complaints`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_party_resident` FOREIGN KEY (`resident_id`)
    REFERENCES `residents`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- complaints.filed_by_type / filed_by_official_id
-- ---------------------------------------------------------------------------
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE() AND table_name = 'complaints' AND column_name = 'filed_by_type') = 0,
  'ALTER TABLE complaints ADD COLUMN filed_by_type ENUM(''resident'',''official'') NOT NULL DEFAULT ''resident'' AFTER resident_name',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE() AND table_name = 'complaints' AND column_name = 'filed_by_official_id') = 0,
  'ALTER TABLE complaints ADD COLUMN filed_by_official_id INT NULL DEFAULT NULL AFTER filed_by_type',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.statistics
                WHERE table_schema = DATABASE() AND table_name = 'complaints' AND index_name = 'idx_complaint_filed_by') = 0,
  'ALTER TABLE complaints ADD KEY idx_complaint_filed_by (filed_by_official_id)',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.table_constraints
                WHERE table_schema = DATABASE() AND table_name = 'complaints'
                  AND constraint_name = 'fk_complaint_filed_by' AND constraint_type = 'FOREIGN KEY') = 0,
  'ALTER TABLE complaints ADD CONSTRAINT fk_complaint_filed_by FOREIGN KEY (filed_by_official_id) REFERENCES barangay_officials(id) ON DELETE SET NULL',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- Backfill: one complainant per complaint that has none yet. Registered when
-- complaints.resident_id is set, otherwise not registered. The name is the
-- resident_name the complaint was filed with.
-- The seed-mode test (complaint-types/run.php) runs the statement between the
-- two markers; keep it a single statement.
-- ---------------------------------------------------------------------------
-- BACKFILL BEGIN
INSERT INTO complaint_parties (complaint_id, role, resident_id, full_name, sort_order, created_by_type)
SELECT c.id, 'complainant', c.resident_id, LEFT(TRIM(c.resident_name), 150), 0, 'system'
FROM complaints c
WHERE NOT EXISTS (SELECT 1 FROM complaint_parties p
                  WHERE p.complaint_id = c.id AND p.role = 'complainant')
-- BACKFILL END
;

SELECT
  (SELECT COUNT(*) FROM complaint_parties WHERE created_by_type = 'system' AND resident_id IS NOT NULL) AS backfilled_registered,
  (SELECT COUNT(*) FROM complaint_parties WHERE created_by_type = 'system' AND resident_id IS NULL) AS backfilled_unregistered,
  (SELECT COUNT(*) FROM complaints c WHERE NOT EXISTS (SELECT 1 FROM complaint_parties p
     WHERE p.complaint_id = c.id AND p.role = 'complainant' AND p.is_active = 1)) AS complaints_without_complainant;
