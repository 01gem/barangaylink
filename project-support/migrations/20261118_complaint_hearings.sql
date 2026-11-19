-- Complaint revision Phase 6: mediation calendar (hearings) and barangay case numbers.
-- Additive. Back up the database and test on a disposable copy first.
-- Safe to re-run: tables use IF NOT EXISTS, every ALTER is guarded through
-- information_schema, and the guard row uses INSERT IGNORE.
-- Needs Phase 5 (complaint_parties). This file never selects a database.
-- All hearing DATETIMEs are Asia/Manila local time written by PHP
-- (api/hearings/_hearings.php); the column defaults are a backstop only.

-- ---------------------------------------------------------------------------
-- complaint_case_counters: one row per year; last_no = last BC-YYYY-NNNN issued.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `complaint_case_counters` (
  `year` SMALLINT NOT NULL,
  `last_no` INT NOT NULL,
  PRIMARY KEY (`year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- complaints.case_no (assigned on the first scheduling, never changed)
-- ---------------------------------------------------------------------------
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE() AND table_name = 'complaints' AND column_name = 'case_no') = 0,
  'ALTER TABLE complaints ADD COLUMN case_no VARCHAR(20) NULL DEFAULT NULL AFTER reference_no',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.statistics
                WHERE table_schema = DATABASE() AND table_name = 'complaints' AND index_name = 'uq_complaint_case_no') = 0,
  'ALTER TABLE complaints ADD UNIQUE KEY uq_complaint_case_no (case_no)',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- complaint_hearing_guard: one row. Every hearing write locks it FOR UPDATE
-- first, so overlap checks and case numbers are decided one at a time.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `complaint_hearing_guard` (
  `id` TINYINT NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO complaint_hearing_guard (id) VALUES (1);

-- ---------------------------------------------------------------------------
-- complaint_hearings. A reschedule keeps the old row (status 'rescheduled')
-- and adds a new session (session_no + 1, rescheduled_from_id).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `complaint_hearings` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `complaint_id` INT NOT NULL,
  `session_no` INT NOT NULL,
  `starts_at` DATETIME NOT NULL,
  `ends_at` DATETIME NOT NULL,
  `venue` VARCHAR(180) NOT NULL,
  `venue_key` VARCHAR(180) NOT NULL,
  `presiding_official_id` INT NOT NULL,
  `status` ENUM('scheduled','completed','cancelled','rescheduled') NOT NULL DEFAULT 'scheduled',
  `outcome` ENUM('settled','for_follow_up','referred','no_agreement') NULL DEFAULT NULL,
  `public_summary` VARCHAR(1000) NULL DEFAULT NULL,
  `private_notes` TEXT NULL,
  `rescheduled_from_id` INT NULL DEFAULT NULL,
  `change_reason` VARCHAR(500) NULL DEFAULT NULL,
  `request_key` CHAR(32) NULL DEFAULT NULL,
  `version` INT NOT NULL DEFAULT 1,
  `created_by` INT NOT NULL,
  `updated_by` INT NOT NULL,
  `completed_at` DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hearing_session` (`complaint_id`, `session_no`),
  UNIQUE KEY `uq_hearing_request_key` (`request_key`),
  KEY `idx_hearing_calendar` (`status`, `starts_at`),
  KEY `idx_hearing_official` (`presiding_official_id`, `status`, `starts_at`),
  KEY `idx_hearing_venue` (`venue_key`, `status`, `starts_at`),
  KEY `idx_hearing_complaint` (`complaint_id`, `status`),
  KEY `idx_hearing_from` (`rescheduled_from_id`),
  KEY `idx_hearing_created_by` (`created_by`),
  KEY `idx_hearing_updated_by` (`updated_by`),
  CONSTRAINT `chk_hearing_times` CHECK (`ends_at` > `starts_at`),
  CONSTRAINT `fk_hearing_complaint` FOREIGN KEY (`complaint_id`)
    REFERENCES `complaints`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_hearing_presiding` FOREIGN KEY (`presiding_official_id`)
    REFERENCES `barangay_officials`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_hearing_from` FOREIGN KEY (`rescheduled_from_id`)
    REFERENCES `complaint_hearings`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_hearing_created_by` FOREIGN KEY (`created_by`)
    REFERENCES `barangay_officials`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_hearing_updated_by` FOREIGN KEY (`updated_by`)
    REFERENCES `barangay_officials`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- complaint_hearing_attendance: one row per party active when the session was
-- scheduled. Parties added later are not added retroactively.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `complaint_hearing_attendance` (
  `hearing_id` INT NOT NULL,
  `party_id` INT NOT NULL,
  `status` ENUM('not_recorded','present','absent','excused') NOT NULL DEFAULT 'not_recorded',
  PRIMARY KEY (`hearing_id`, `party_id`),
  KEY `idx_attendance_party` (`party_id`),
  CONSTRAINT `fk_attendance_hearing` FOREIGN KEY (`hearing_id`)
    REFERENCES `complaint_hearings`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_attendance_party` FOREIGN KEY (`party_id`)
    REFERENCES `complaint_parties`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Expected on a first run: guard_rows 1, hearings 0, case_numbers 0, counters 0.
SELECT
  (SELECT COUNT(*) FROM complaint_hearing_guard) AS guard_rows,
  (SELECT COUNT(*) FROM complaint_hearings) AS hearings,
  (SELECT COUNT(*) FROM complaints WHERE case_no IS NOT NULL) AS case_numbers,
  (SELECT COUNT(*) FROM complaint_case_counters) AS counters;
