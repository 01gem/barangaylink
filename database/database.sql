-- ============================================================================
-- BarangayLink - Consolidated schema
-- Copyright (C) 2026 Jun Gem Riege M. Dulduco
-- Licensed under the GNU General Public License v3.0 (or later).
-- ============================================================================
-- This file replaces database.sql plus every migration_*.sql that shipped
-- with the project.
--
-- Import into an EMPTY database you have selected yourself, e.g.:
-- mysql -uroot --default-character-set=utf8mb4 <target_db> -e "source database/database.sql"
-- This file never selects a database.
--
-- Importing it a second time into the same database is safe: tables use
-- IF NOT EXISTS, ALTERs are guarded, and seed rows are skipped when present.
-- Never import it into an existing installation to upgrade it; use
-- project-support/migrations/ for that.
--
-- test-resident-data(for demonstration).sql (run after this file, against the same database)
-- depends on poverty_thresholds.id = 1 being the active Province-level
-- threshold for Surigao del Norte. See the INSERT below.
-- ============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
-- residents
-- household_id FK added after households exists (circular reference).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `residents` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `fname` VARCHAR(50) NOT NULL,
  `lname` VARCHAR(50) NOT NULL,
  `username` VARCHAR(50) NOT NULL,
  `address` VARCHAR(100) NOT NULL,
  `contact` VARCHAR(50) NOT NULL,
  -- Normalized contact for uniqueness: blank -> NULL (never collides),
  -- PH mobile in any accepted format -> +639XXXXXXXXX, otherwise the trimmed
  -- raw value. Same expression as project-support/migrations/20261121_contact_unique.sql.
  `contact_key` VARCHAR(50) GENERATED ALWAYS AS (CASE
    WHEN TRIM(`contact`) = '' THEN NULL
    WHEN REGEXP_LIKE(REGEXP_REPLACE(TRIM(`contact`), '[[:space:]().-]', ''), '^[+]?(63|0)?9[0-9]{9}$')
      THEN CONCAT('+63', RIGHT(REGEXP_REPLACE(TRIM(`contact`), '[[:space:]().-]', ''), 10))
    ELSE TRIM(`contact`) END) STORED,
  `password` VARCHAR(255) NOT NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `profile_photo` VARCHAR(255) DEFAULT NULL,
  `birthdate` DATE NULL,
  `civil_status` VARCHAR(30) NULL,
  `purok_zone` VARCHAR(50) NULL,
  `household_size` INT DEFAULT 1,
  `is_solo_parent` TINYINT(1) DEFAULT 0,
  `is_pwd` TINYINT(1) DEFAULT 0,
  `is_4ps_member` TINYINT(1) DEFAULT 0,
  `educational_attainment` VARCHAR(50) NULL,
  `employment_status` ENUM('Employed','Unemployed','Self-Employed','Student','Retired') NULL,
  `occupation` VARCHAR(150) NULL,
  `training_certifications` TEXT NULL,
  `work_availability` ENUM('Full-time','Part-time','Seasonal','Not looking') NULL,
  `skill_category` VARCHAR(50) NULL,
  `failed_logins` INT NOT NULL DEFAULT 0,
  `locked_until` DATETIME NULL DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `household_id` INT NOT NULL,
  `relationship_to_household_head` VARCHAR(50) NULL,
  `profile_status` ENUM('unprofiled','in_progress','complete') NOT NULL DEFAULT 'unprofiled',
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `uq_residents_contact_key` (`contact_key`),
  KEY `idx_household` (`household_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- barangay_officials
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `barangay_officials` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `fname` VARCHAR(50) NOT NULL,
  `lname` VARCHAR(50) NOT NULL,
  `username` VARCHAR(50) NOT NULL,
  `role` ENUM('staff','admin') NOT NULL DEFAULT 'staff',
  `address` VARCHAR(100) NOT NULL,
  `contact` VARCHAR(50) NOT NULL,
  -- Same normalized key as residents.contact_key (unique per table).
  `contact_key` VARCHAR(50) GENERATED ALWAYS AS (CASE
    WHEN TRIM(`contact`) = '' THEN NULL
    WHEN REGEXP_LIKE(REGEXP_REPLACE(TRIM(`contact`), '[[:space:]().-]', ''), '^[+]?(63|0)?9[0-9]{9}$')
      THEN CONCAT('+63', RIGHT(REGEXP_REPLACE(TRIM(`contact`), '[[:space:]().-]', ''), 10))
    ELSE TRIM(`contact`) END) STORED,
  `password` VARCHAR(255) NOT NULL,
  `position` VARCHAR(100) NOT NULL DEFAULT '',
  `profile_photo` VARCHAR(255) DEFAULT NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `failed_logins` INT NOT NULL DEFAULT 0,
  `locked_until` DATETIME NULL DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `uq_officials_contact_key` (`contact_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Unique contacts (20261121_contact_unique.sql): when the CREATE TABLEs above
-- are skipped (re-import), the same guarded upgrade runs here. On a fresh
-- install every statement is a no-op. No pre-check here: this file is never an
-- upgrade path (use the migration, which aborts on duplicates/invalid values).
SET @bl_contact_key_expr := 'CASE WHEN TRIM(contact) = '''' THEN NULL WHEN REGEXP_LIKE(REGEXP_REPLACE(TRIM(contact), ''[[:space:]().-]'', ''''), ''^[+]?(63|0)?9[0-9]{9}$'') THEN CONCAT(''+63'', RIGHT(REGEXP_REPLACE(TRIM(contact), ''[[:space:]().-]'', ''''), 10)) ELSE TRIM(contact) END';

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE() AND table_name = 'residents' AND column_name = 'contact_key') = 0,
  CONCAT('ALTER TABLE residents ADD COLUMN contact_key VARCHAR(50) GENERATED ALWAYS AS (', @bl_contact_key_expr, ') STORED AFTER contact'),
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.statistics
                WHERE table_schema = DATABASE() AND table_name = 'residents' AND index_name = 'uq_residents_contact_key') = 0,
  'ALTER TABLE residents ADD UNIQUE KEY uq_residents_contact_key (contact_key)',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE() AND table_name = 'barangay_officials' AND column_name = 'contact_key') = 0,
  CONCAT('ALTER TABLE barangay_officials ADD COLUMN contact_key VARCHAR(50) GENERATED ALWAYS AS (', @bl_contact_key_expr, ') STORED AFTER contact'),
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.statistics
                WHERE table_schema = DATABASE() AND table_name = 'barangay_officials' AND index_name = 'uq_officials_contact_key') = 0,
  'ALTER TABLE barangay_officials ADD UNIQUE KEY uq_officials_contact_key (contact_key)',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- poverty_thresholds
-- IMPORTANT: The Province row must be id = 1 for test-resident-data(for demonstration).sql.
-- Insert order below guarantees this on a fresh database.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `poverty_thresholds` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `reference_year` INT NOT NULL,
  `geography_level` ENUM('Province','Region','National') NOT NULL,
  `region` VARCHAR(100) NULL,
  `province` VARCHAR(100) NULL,
  `annual_per_capita_threshold` DECIMAL(12,2) NOT NULL,
  `source` VARCHAR(255) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_lookup` (`reference_year`, `geography_level`, `province`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Re-run safe: a row is added only when no row with the same year, level,
-- region and province exists (the table has no unique key for this).
INSERT INTO `poverty_thresholds`
  (`reference_year`, `geography_level`, `region`, `province`,
   `annual_per_capita_threshold`, `source`, `is_active`)
SELECT s.reference_year, s.geography_level, s.region, s.province,
       s.annual_per_capita_threshold, s.source, s.is_active
FROM (
  SELECT 1 AS seq, 2023 AS reference_year, 'Province' AS geography_level, 'Caraga' AS region, 'Surigao del Norte' AS province,
         29865.00 AS annual_per_capita_threshold, 'PSA 2023 Full Year Official Poverty Statistics' AS source, 1 AS is_active
  UNION ALL SELECT 2, 2023, 'Region', 'Caraga', NULL, 27911.00,
         'PSA 2023 Full Year Official Poverty Statistics (placeholder - verify before use)', 0
  UNION ALL SELECT 3, 2023, 'National', NULL, NULL, 26779.00,
         'PSA 2023 Full Year Official Poverty Statistics (placeholder - verify before use)', 0
) AS s
WHERE NOT EXISTS (
  SELECT 1 FROM `poverty_thresholds` p
  WHERE p.reference_year = s.reference_year AND p.geography_level = s.geography_level
    AND p.region <=> s.region AND p.province <=> s.province
)
ORDER BY s.seq;

-- ----------------------------------------------------------------------------
-- households
-- FKs to residents and poverty_thresholds both exist; residents.household_id
-- FK is added after this table so the circular reference resolves cleanly.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `households` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `household_code` VARCHAR(20) NOT NULL,
  `head_resident_id` INT NULL,
  `address` VARCHAR(255) NULL,
  `purok_zone` VARCHAR(50) NULL,
  `household_size` INT NOT NULL DEFAULT 0,
  `profile_status` ENUM('unprofiled','in_progress','complete') NOT NULL DEFAULT 'unprofiled',
  `profile_completeness` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `profiled_at` DATETIME NULL,
  `assessment_complete` TINYINT(1) NOT NULL DEFAULT 0,
  `assessment_method_version` VARCHAR(40) NULL,
  `mpi_score` DECIMAL(5,4) NULL,
  `mpi_status` ENUM('poor','not_poor','incomplete') NULL,
  `annual_household_income` DECIMAL(12,2) NULL,
  `annual_per_capita_income` DECIMAL(12,2) NULL,
  `income_poverty_status` ENUM('below','at_or_above','cannot_determine') NULL,
  `applied_threshold_id` INT NULL,
  `mpi_indicators_json` JSON NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `household_code` (`household_code`),
  KEY `idx_purok` (`purok_zone`),
  KEY `idx_status` (`profile_status`),
  KEY `idx_head` (`head_resident_id`),
  CONSTRAINT `fk_household_head` FOREIGN KEY (`head_resident_id`)
    REFERENCES `residents`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_household_threshold` FOREIGN KEY (`applied_threshold_id`)
    REFERENCES `poverty_thresholds`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Guarded so a second import does not fail with a duplicate constraint (1826).
SET @sql := IF((SELECT COUNT(*) FROM information_schema.table_constraints
                WHERE table_schema = DATABASE() AND table_name = 'residents'
                  AND constraint_name = 'fk_resident_household' AND constraint_type = 'FOREIGN KEY') = 0,
  'ALTER TABLE `residents` ADD CONSTRAINT `fk_resident_household` FOREIGN KEY (`household_id`) REFERENCES `households`(`id`) ON DELETE RESTRICT',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- household_profiles
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `household_profiles` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `household_id` INT NOT NULL,
  `num_children` INT NULL,
  `num_working_age` INT NULL,
  `num_seniors` INT NULL,
  `has_4ps` TINYINT(1) NULL,
  `has_pwd_member` TINYINT(1) NULL,
  `has_senior_member` TINYINT(1) NULL,
  `is_solo_parent_hh` TINYINT(1) NULL,
  `children_5_17_total` INT NULL,
  `children_5_17_not_in_school` INT NULL,
  `members_19_plus_total` INT NULL,
  `has_19_plus_secondary_grad` TINYINT(1) NULL,
  `medical_need_6mo` TINYINT(1) NULL,
  `medical_treatment_obtained` TINYINT(1) NULL,
  `distance_health_center_km` DECIMAL(6,2) NULL,
  `distance_hospital_km` DECIMAL(6,2) NULL,
  `fies_worried_food` TINYINT(1) NULL,
  `fies_no_nutritious` TINYINT(1) NULL,
  `fies_few_food_types` TINYINT(1) NULL,
  `fies_skipped_meals` TINYINT(1) NULL,
  `fies_ate_less` TINYINT(1) NULL,
  `fies_ran_out` TINYINT(1) NULL,
  `fies_hungry` TINYINT(1) NULL,
  `fies_whole_day_no_food` TINYINT(1) NULL,
  `labor_force_total` INT NULL,
  `employed_count` INT NULL,
  `unemployed_seeking_count` INT NULL,
  `employed_wanting_more_hours` INT NULL,
  `working_children_5_17` INT NULL,
  `working_children_not_in_school` INT NULL,
  `housing_tenure` VARCHAR(50) NULL,
  `roof_material` VARCHAR(50) NULL,
  `wall_material` VARCHAR(50) NULL,
  `floor_area_sqm` DECIMAL(8,2) NULL,
  `main_water_source` VARCHAR(80) NULL,
  `toilet_facility` VARCHAR(80) NULL,
  `shared_toilet` TINYINT(1) NULL,
  `has_electricity` TINYINT(1) NULL,
  `has_internet` TINYINT(1) NULL,
  `public_transport_access` TINYINT(1) NULL,
  `assets_json` JSON NULL,
  `monthly_wages` DECIMAL(12,2) NULL,
  `monthly_business` DECIMAL(12,2) NULL,
  `monthly_remittances` DECIMAL(12,2) NULL,
  `monthly_pension` DECIMAL(12,2) NULL,
  `monthly_govt_assistance` DECIMAL(12,2) NULL,
  `monthly_other` DECIMAL(12,2) NULL,
  `contextual_flags_json` JSON NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `household_id` (`household_id`),
  CONSTRAINT `fk_profile_household` FOREIGN KEY (`household_id`)
    REFERENCES `households`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- otp_codes
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `otp_codes` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `account_type` ENUM('resident','official') NOT NULL,
  `account_id` INT NOT NULL,
  `code` VARCHAR(6) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `used` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `attempts` TINYINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_otp_account` (`account_type`,`account_id`,`used`,`expires_at`),
  KEY `idx_otp_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- document_requests
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `document_requests` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `reference_no` VARCHAR(50) NOT NULL,
  `resident_id` INT NOT NULL,
  `resident_name` VARCHAR(100) NOT NULL,
  `document_type` VARCHAR(100) NOT NULL,
  `purpose` VARCHAR(150) NOT NULL,
  `request_fields_json` JSON NULL,
  `copies` INT NOT NULL DEFAULT 1,
  `total_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `payment_status` ENUM('unpaid','paid','waived') NOT NULL DEFAULT 'unpaid',
  `date_requested` VARCHAR(30) NOT NULL,
  `status` VARCHAR(30) NOT NULL DEFAULT 'pending',
  `date_completed` DATETIME NULL DEFAULT NULL,
  `generated_document_path` VARCHAR(255) DEFAULT NULL,
  `template_version_id` INT NULL DEFAULT NULL,
  row_version INT UNSIGNED NOT NULL DEFAULT 1,
  readiness_cycle INT UNSIGNED NOT NULL DEFAULT 0,
  generation_token CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  generation_expires_at DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `reference_no` (`reference_no`),
  KEY `idx_request_template_version` (`template_version_id`),
  CONSTRAINT `fk_request_resident` FOREIGN KEY (`resident_id`)
    REFERENCES `residents`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- document_prices
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `document_prices` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `document_type` VARCHAR(100) NOT NULL,
  `unit_price` DECIMAL(10,2) NOT NULL DEFAULT 20.00,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `document_type` (`document_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `document_prices` (`document_type`, `unit_price`) VALUES
  ('Barangay Clearance', 20.00),
  ('Certificate of Residency', 20.00),
  ('Certificate of Indigency', 20.00),
  ('Certificate of Good Moral Character', 20.00),
  ('Business Permit Endorsement', 20.00)
ON DUPLICATE KEY UPDATE `unit_price` = `unit_price`;

-- ----------------------------------------------------------------------------
-- document registry
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `document_types` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `code` VARCHAR(50) NULL,
  `template_file` VARCHAR(255) NOT NULL,
  `status` ENUM('active','archived') NOT NULL DEFAULT 'active',
  `audience` ENUM('resident','internal') NOT NULL DEFAULT 'resident',
  `sort_order` INT NOT NULL DEFAULT 0,
  `processing_days` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `requirements` VARCHAR(1000) NULL,
  `created_by` INT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `document_type_name` (`name`),
  UNIQUE KEY `document_type_code` (`code`),
  CONSTRAINT `fk_document_types_created_by`
    FOREIGN KEY (`created_by`) REFERENCES `barangay_officials`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `document_type_fields` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `document_type_id` INT NOT NULL,
  `field_key` VARCHAR(50) NOT NULL,
  `label` VARCHAR(100) NOT NULL,
  `source` ENUM('resident_record','resident_supplied','staff_entered','system') NOT NULL,
  `data_type` ENUM('text','int') NOT NULL DEFAULT 'text',
  `min_value` INT NULL,
  `max_value` INT NULL,
  `max_length` INT NULL,
  `is_required` TINYINT(1) NOT NULL DEFAULT 0,
  `sort_order` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `document_type_field_key` (`document_type_id`, `field_key`),
  CONSTRAINT `fk_document_type_fields_type`
    FOREIGN KEY (`document_type_id`) REFERENCES `document_types`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `document_settings` (
  `setting_key` VARCHAR(100) NOT NULL,
  `setting_value` VARCHAR(255) NOT NULL,
  `updated_by` INT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`),
  CONSTRAINT `fk_document_settings_official`
    FOREIGN KEY (`updated_by`) REFERENCES `barangay_officials`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `document_types` (`name`, `template_file`, `status`, `sort_order`) VALUES
  ('Barangay Clearance', 'barangay_clearance.docx', 'active', 1),
  ('Certificate of Residency', 'certificate_of_residency.docx', 'active', 2),
  ('Certificate of Indigency', 'certificate_of_indigency.docx', 'active', 3),
  ('Certificate of Good Moral Character', 'certificate_of_good_moral_character.docx', 'active', 4),
  ('Business Permit Endorsement', 'business_permit_endorsement.docx', 'active', 5);

-- Phase 5A (20261115_document_type_create.sql): on an older install the
-- CREATE TABLE above is skipped, so the same guarded upgrade runs here too.
-- On a fresh install every ALTER is a no-op and only the codes are filled.
-- code: stable lowercase identifier (unique; NULL allowed for legacy rows)
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE() AND table_name = 'document_types' AND column_name = 'code') = 0,
  'ALTER TABLE document_types ADD COLUMN code VARCHAR(50) NULL AFTER name',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE() AND table_name = 'document_types' AND column_name = 'processing_days') = 0,
  'ALTER TABLE document_types ADD COLUMN processing_days TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER sort_order',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE() AND table_name = 'document_types' AND column_name = 'requirements') = 0,
  'ALTER TABLE document_types ADD COLUMN requirements VARCHAR(1000) NULL AFTER processing_days',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE() AND table_name = 'document_types' AND column_name = 'created_by') = 0,
  'ALTER TABLE document_types ADD COLUMN created_by INT NULL AFTER requirements',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Codes for the five seeded types (only rows that have none yet; updated_at kept).
UPDATE document_types SET code = CASE name
  WHEN 'Barangay Clearance' THEN 'barangay_clearance'
  WHEN 'Certificate of Residency' THEN 'certificate_of_residency'
  WHEN 'Certificate of Indigency' THEN 'certificate_of_indigency'
  WHEN 'Certificate of Good Moral Character' THEN 'certificate_of_good_moral_character'
  WHEN 'Business Permit Endorsement' THEN 'business_permit_endorsement'
  ELSE code
END,
    updated_at = updated_at
WHERE code IS NULL;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.statistics
                WHERE table_schema = DATABASE() AND table_name = 'document_types' AND index_name = 'document_type_code') = 0,
  'ALTER TABLE document_types ADD UNIQUE KEY document_type_code (code)',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.table_constraints
                WHERE constraint_schema = DATABASE() AND table_name = 'document_types'
                  AND constraint_name = 'fk_document_types_created_by' AND constraint_type = 'FOREIGN KEY') = 0,
  'ALTER TABLE document_types ADD CONSTRAINT fk_document_types_created_by FOREIGN KEY (created_by) REFERENCES barangay_officials(id) ON DELETE SET NULL',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- audience (complaint revision Phase 7): 'internal' types (Summons) are never shown
-- to or requested by residents. Same guarded ALTER as 20261119_summons.sql.
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE() AND table_name = 'document_types' AND column_name = 'audience') = 0,
  "ALTER TABLE document_types ADD COLUMN audience ENUM('resident','internal') NOT NULL DEFAULT 'resident' AFTER status",
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO `document_type_fields`
  (`document_type_id`, `field_key`, `label`, `source`, `data_type`, `min_value`, `max_value`, `max_length`, `is_required`, `sort_order`)
SELECT dt.id, v.field_key, v.label, v.source, v.data_type, v.min_value, v.max_value, v.max_length, v.is_required, v.sort_order
FROM document_types dt
JOIN (
  SELECT 'Barangay Clearance' name, 'full_name' field_key, 'Full name' label, 'resident_record' source, 'text' data_type, NULL min_value, NULL max_value, NULL max_length, 1 is_required, 1 sort_order
  UNION ALL SELECT 'Barangay Clearance', 'age', 'Age', 'resident_supplied', 'int', 0, 120, NULL, 0, 2
  UNION ALL SELECT 'Barangay Clearance', 'civil_status', 'Civil status', 'resident_supplied', 'text', NULL, NULL, 100, 0, 3
  UNION ALL SELECT 'Barangay Clearance', 'address', 'Address', 'resident_record', 'text', NULL, NULL, NULL, 1, 4
  UNION ALL SELECT 'Barangay Clearance', 'purpose', 'Purpose', 'staff_entered', 'text', NULL, NULL, NULL, 1, 5
  UNION ALL SELECT 'Barangay Clearance', 'date_issued', 'Date issued', 'staff_entered', 'text', NULL, NULL, NULL, 1, 6
  UNION ALL SELECT 'Barangay Clearance', 'reference_no', 'Reference number', 'system', 'text', NULL, NULL, NULL, 1, 7
  UNION ALL SELECT 'Barangay Clearance', 'official_name', 'Official name', 'system', 'text', NULL, NULL, NULL, 1, 8
  UNION ALL SELECT 'Barangay Clearance', 'official_title', 'Official title', 'system', 'text', NULL, NULL, NULL, 1, 9
  UNION ALL SELECT 'Certificate of Residency', 'full_name', 'Full name', 'resident_record', 'text', NULL, NULL, NULL, 1, 1
  UNION ALL SELECT 'Certificate of Residency', 'address', 'Address', 'resident_record', 'text', NULL, NULL, NULL, 1, 2
  UNION ALL SELECT 'Certificate of Residency', 'years_of_residency', 'Years of residency', 'resident_supplied', 'int', 0, 120, NULL, 0, 3
  UNION ALL SELECT 'Certificate of Residency', 'purpose', 'Purpose', 'staff_entered', 'text', NULL, NULL, NULL, 1, 4
  UNION ALL SELECT 'Certificate of Residency', 'date_issued', 'Date issued', 'staff_entered', 'text', NULL, NULL, NULL, 1, 5
  UNION ALL SELECT 'Certificate of Residency', 'reference_no', 'Reference number', 'system', 'text', NULL, NULL, NULL, 1, 6
  UNION ALL SELECT 'Certificate of Residency', 'official_name', 'Official name', 'system', 'text', NULL, NULL, NULL, 1, 7
  UNION ALL SELECT 'Certificate of Residency', 'official_title', 'Official title', 'system', 'text', NULL, NULL, NULL, 1, 8
  UNION ALL SELECT 'Certificate of Indigency', 'full_name', 'Full name', 'resident_record', 'text', NULL, NULL, NULL, 1, 1
  UNION ALL SELECT 'Certificate of Indigency', 'address', 'Address', 'resident_record', 'text', NULL, NULL, NULL, 1, 2
  UNION ALL SELECT 'Certificate of Indigency', 'purpose', 'Purpose', 'staff_entered', 'text', NULL, NULL, NULL, 1, 3
  UNION ALL SELECT 'Certificate of Indigency', 'date_issued', 'Date issued', 'staff_entered', 'text', NULL, NULL, NULL, 1, 4
  UNION ALL SELECT 'Certificate of Indigency', 'reference_no', 'Reference number', 'system', 'text', NULL, NULL, NULL, 1, 5
  UNION ALL SELECT 'Certificate of Indigency', 'official_name', 'Official name', 'system', 'text', NULL, NULL, NULL, 1, 6
  UNION ALL SELECT 'Certificate of Indigency', 'official_title', 'Official title', 'system', 'text', NULL, NULL, NULL, 1, 7
  UNION ALL SELECT 'Certificate of Good Moral Character', 'full_name', 'Full name', 'resident_record', 'text', NULL, NULL, NULL, 1, 1
  UNION ALL SELECT 'Certificate of Good Moral Character', 'address', 'Address', 'resident_record', 'text', NULL, NULL, NULL, 1, 2
  UNION ALL SELECT 'Certificate of Good Moral Character', 'purpose', 'Purpose', 'staff_entered', 'text', NULL, NULL, NULL, 1, 3
  UNION ALL SELECT 'Certificate of Good Moral Character', 'date_issued', 'Date issued', 'staff_entered', 'text', NULL, NULL, NULL, 1, 4
  UNION ALL SELECT 'Certificate of Good Moral Character', 'reference_no', 'Reference number', 'system', 'text', NULL, NULL, NULL, 1, 5
  UNION ALL SELECT 'Certificate of Good Moral Character', 'official_name', 'Official name', 'system', 'text', NULL, NULL, NULL, 1, 6
  UNION ALL SELECT 'Certificate of Good Moral Character', 'official_title', 'Official title', 'system', 'text', NULL, NULL, NULL, 1, 7
  UNION ALL SELECT 'Business Permit Endorsement', 'owner_name', 'Owner name', 'resident_record', 'text', NULL, NULL, NULL, 1, 1
  UNION ALL SELECT 'Business Permit Endorsement', 'business_name', 'Business name', 'resident_supplied', 'text', NULL, NULL, 100, 0, 2
  UNION ALL SELECT 'Business Permit Endorsement', 'business_type', 'Business type', 'resident_supplied', 'text', NULL, NULL, 100, 0, 3
  UNION ALL SELECT 'Business Permit Endorsement', 'business_address', 'Business address', 'resident_record', 'text', NULL, NULL, NULL, 1, 4
  UNION ALL SELECT 'Business Permit Endorsement', 'purpose', 'Purpose', 'staff_entered', 'text', NULL, NULL, NULL, 1, 5
  UNION ALL SELECT 'Business Permit Endorsement', 'date_issued', 'Date issued', 'staff_entered', 'text', NULL, NULL, NULL, 1, 6
  UNION ALL SELECT 'Business Permit Endorsement', 'reference_no', 'Reference number', 'system', 'text', NULL, NULL, NULL, 1, 7
  UNION ALL SELECT 'Business Permit Endorsement', 'official_name', 'Official name', 'system', 'text', NULL, NULL, NULL, 1, 8
  UNION ALL SELECT 'Business Permit Endorsement', 'official_title', 'Official title', 'system', 'text', NULL, NULL, NULL, 1, 9
) v ON v.name = dt.name;

-- ----------------------------------------------------------------------------
-- document template versions
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `document_template_versions` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `document_type_id` INT NOT NULL,
  `version_no` INT NOT NULL,
  `file_name` VARCHAR(255) NOT NULL,
  `original_name` VARCHAR(255) NULL,
  `sha256` CHAR(64) NULL,
  `size_bytes` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `status` ENUM('draft','active','retired','discarded') NOT NULL DEFAULT 'draft',
  `notes` VARCHAR(255) NULL,
  `check_report` TEXT NULL,
  `check_ok` TINYINT(1) NOT NULL DEFAULT 0,
  `tested_sha256` CHAR(64) NULL,
  `tested_at` DATETIME NULL,
  `test_ok` TINYINT(1) NOT NULL DEFAULT 0,
  `created_by` INT NULL,
  `activated_by` INT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `activated_at` DATETIME NULL,
  `active_flag` TINYINT GENERATED ALWAYS AS (IF(`status` = 'active', 1, NULL)) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `document_template_version_file` (`file_name`),
  UNIQUE KEY `document_template_version_number` (`document_type_id`, `version_no`),
  UNIQUE KEY `document_template_one_active` (`document_type_id`, `active_flag`),
  CONSTRAINT `fk_template_versions_type` FOREIGN KEY (`document_type_id`) REFERENCES `document_types`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_template_versions_created_by` FOREIGN KEY (`created_by`) REFERENCES `barangay_officials`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_template_versions_activated_by` FOREIGN KEY (`activated_by`) REFERENCES `barangay_officials`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `document_template_versions`
  (`document_type_id`, `version_no`, `file_name`, `original_name`, `status`,
   `notes`, `check_ok`, `test_ok`)
SELECT dt.id, 1, dt.template_file, dt.template_file, 'active',
       'Initial version', 1, 1
FROM document_types dt
WHERE NOT EXISTS (
  SELECT 1 FROM document_template_versions v
  WHERE v.document_type_id = dt.id
)
-- Phase 7: the blank Summons original is never a version; build-summons-template.php makes v1.
AND dt.template_file <> 'summon_letter.docx';

-- document_requests.template_version_id -> document_template_versions. Guarded ALTER
-- because document_requests is created before that table. Same DDL as
-- project-support/migrations/20261122_request_template_version.sql.
SET @sql := IF((SELECT COUNT(*) FROM information_schema.tables
                WHERE table_schema = DATABASE() AND table_name = 'document_template_versions') = 1
           AND (SELECT COUNT(*) FROM information_schema.table_constraints
                WHERE constraint_schema = DATABASE() AND table_name = 'document_requests'
                  AND constraint_name = 'fk_request_template_version' AND constraint_type = 'FOREIGN KEY') = 0,
  'ALTER TABLE document_requests ADD CONSTRAINT fk_request_template_version FOREIGN KEY (template_version_id) REFERENCES document_template_versions(id) ON DELETE RESTRICT',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- Summons (complaint revision Phase 7): internal type, officials only.
-- Inserted after the version backfill above. Template v1 comes from
-- project-support/scripts/build-summons-template.php (summon_letter.docx is only read).
-- Same rows as project-support/migrations/20261119_summons.sql.
-- ----------------------------------------------------------------------------
INSERT INTO `document_types` (`name`, `code`, `template_file`, `status`, `audience`, `sort_order`, `processing_days`)
SELECT 'Summons', 'summons', 'summon_letter.docx', 'active', 'internal', 1000, 0 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM document_types WHERE code = 'summons' OR name = 'Summons');

INSERT IGNORE INTO `document_type_fields`
  (`document_type_id`, `field_key`, `label`, `source`, `data_type`, `min_value`, `max_value`, `max_length`, `is_required`, `sort_order`)
SELECT dt.id, f.field_key, f.label, 'system', 'text', NULL, NULL, NULL, 1, f.sort_order
FROM document_types dt
JOIN (
            SELECT 'case_no' field_key, 'Case number' label, 1 sort_order
  UNION ALL SELECT 'case_title', 'Case title', 2
  UNION ALL SELECT 'complainant_names', 'Complainant names', 3
  UNION ALL SELECT 'respondent_names', 'Respondent names', 4
  UNION ALL SELECT 'respondent_to', 'Addressed to', 5
  UNION ALL SELECT 'hearing_day', 'Hearing day', 6
  UNION ALL SELECT 'hearing_month_year', 'Hearing month and year', 7
  UNION ALL SELECT 'hearing_time', 'Hearing time', 8
  UNION ALL SELECT 'venue', 'Venue', 9
  UNION ALL SELECT 'issued_day', 'Issued day', 10
  UNION ALL SELECT 'issued_month', 'Issued month', 11
  UNION ALL SELECT 'issued_year', 'Issued year', 12
  UNION ALL SELECT 'official_name', 'Official name', 13
) f
WHERE dt.code = 'summons' AND dt.audience = 'internal';

INSERT INTO `document_settings` (`setting_key`, `setting_value`)
VALUES ('captain_name', 'Erwin Astronomo')
ON DUPLICATE KEY UPDATE `setting_value` = `setting_value`;

-- ----------------------------------------------------------------------------
-- document template analyses (AI placeholder suggestions, suggest-only)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `document_template_analyses` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `version_id` INT NOT NULL,
  `file_sha256` CHAR(64) NOT NULL,
  `status` ENUM('completed','failed') NOT NULL,
  `model` VARCHAR(100) NULL,
  `paragraph_count` INT NOT NULL DEFAULT 0,
  `suggestions` MEDIUMTEXT NOT NULL,
  `dropped_count` INT NOT NULL DEFAULT 0,
  `created_by` INT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_template_analyses_version` (`version_id`, `id`),
  CONSTRAINT `fk_template_analyses_version` FOREIGN KEY (`version_id`)
    REFERENCES `document_template_versions`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_template_analyses_created_by` FOREIGN KEY (`created_by`)
    REFERENCES `barangay_officials`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- complaint_types
-- type_code is immutable after creation (enforced in application code).
-- Same DDL as project-support/migrations/20261116_complaint_types.sql.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `complaint_types` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `type_code` VARCHAR(50) NOT NULL,
  `type_name` VARCHAR(100) NOT NULL,
  `description` VARCHAR(255) NULL DEFAULT NULL,
  `respondent_mode` ENUM('none','optional','required') NOT NULL DEFAULT 'none',
  `default_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
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

-- ----------------------------------------------------------------------------
-- complaint_type_fields
-- field_key must match ^[a-z][a-z0-9_]{0,49}$ (enforced in application code).
-- options_json (select only), in display order:
--   [{"value":"pothole","label":"Pothole","is_active":1}, ...]
-- No cascade: a type that has fields cannot be deleted.
-- ----------------------------------------------------------------------------
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

-- Seed types and fields. Existing rows are left untouched.
-- Every seeded type lets the resident name a person involved (respondent_mode optional).
INSERT INTO `complaint_types` (`type_code`, `type_name`, `respondent_mode`, `sort_order`) VALUES
  ('road_infrastructure', 'Road / Infrastructure',      'optional', 10),
  ('noise_disturbance',   'Noise / Public Disturbance', 'optional', 20),
  ('flooding_drainage',   'Flooding / Drainage',        'optional', 30),
  ('public_safety',       'Public Safety',              'optional', 40),
  ('other',               'Other',                      'optional', 50)
ON DUPLICATE KEY UPDATE `type_code` = `type_code`;

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

-- "Other": one required free-text field.
INSERT INTO `complaint_type_fields`
  (`complaint_type_id`, `field_key`, `label`, `field_type`, `is_required`, `max_length`, `sort_order`)
SELECT t.`id`, 'specify', 'Please specify', 'text', 1, 150, 10
FROM `complaint_types` t
WHERE t.`type_code` = 'other'
ON DUPLICATE KEY UPDATE `field_key` = VALUES(`field_key`);

-- ----------------------------------------------------------------------------
-- complaints
-- category keeps the type name as filed; complaint_type_id links the type.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `complaints` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `resident_id` INT DEFAULT NULL,
  `reference_no` VARCHAR(50) NOT NULL,
  `case_no` VARCHAR(20) NULL DEFAULT NULL,
  `resident_name` VARCHAR(100) NOT NULL,
  `filed_by_type` ENUM('resident','official') NOT NULL DEFAULT 'resident',
  `filed_by_official_id` INT NULL DEFAULT NULL,
  `category` VARCHAR(100) NOT NULL,
  `complaint_type_id` INT NULL DEFAULT NULL,
  `severity` ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  `location_text` VARCHAR(150) NOT NULL,
  `description` TEXT NOT NULL,
  `date_filed` VARCHAR(30) NOT NULL,
  `status` VARCHAR(30) NOT NULL DEFAULT 'open',
  `official_note` VARCHAR(255) NOT NULL DEFAULT '',
  `resident_confirmation` ENUM('pending','confirmed','reopened') NULL DEFAULT NULL,
  `confirmed_at` DATETIME NULL DEFAULT NULL,
  `reopened_at` DATETIME NULL DEFAULT NULL,
  `extra_fields_json` JSON NULL DEFAULT NULL,
  `assigned_official_id` INT NULL DEFAULT NULL,
  `sla_due_at` DATETIME NULL DEFAULT NULL,
  `escalated_at` DATETIME NULL DEFAULT NULL,
  `is_public` TINYINT(1) NOT NULL DEFAULT 0,
  `duplicate_of_id` INT NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `reference_no` (`reference_no`),
  UNIQUE KEY `uq_complaint_case_no` (`case_no`),
  KEY `idx_complaint_sla` (`status`, `escalated_at`, `sla_due_at`),
  KEY `idx_complaint_public` (`is_public`, `duplicate_of_id`, `id`),
  KEY `idx_complaint_duplicates` (`duplicate_of_id`, `is_public`, `id`),
  KEY `idx_complaint_type` (`complaint_type_id`),
  KEY `idx_complaint_filed_by` (`filed_by_official_id`),
  CONSTRAINT `fk_complaint_resident` FOREIGN KEY (`resident_id`)
    REFERENCES `residents`(`id`),
  CONSTRAINT `fk_complaint_duplicate` FOREIGN KEY (`duplicate_of_id`)
    REFERENCES `complaints`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_complaint_assignee` FOREIGN KEY (`assigned_official_id`)
    REFERENCES `barangay_officials`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_complaint_type` FOREIGN KEY (`complaint_type_id`)
    REFERENCES `complaint_types`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_complaint_filed_by` FOREIGN KEY (`filed_by_official_id`)
    REFERENCES `barangay_officials`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- complaint_parties (complaint revision Phase 5)
-- resident_id NULL = not registered. Registered parties read address and
-- contact live from residents; full_name is the snapshot used on documents.
-- active_resident_id is resident_id while active, else NULL, so a removed
-- registered party never blocks adding the same resident again.
-- fk_party_resident must stay ON DELETE RESTRICT: MySQL forbids CASCADE and
-- SET NULL on a base column of a stored generated column.
-- Same DDL as project-support/migrations/20261117_complaint_parties.sql.
-- ----------------------------------------------------------------------------
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

-- Backfill (no rows on a fresh database): one complainant per complaint.
-- BACKFILL BEGIN
INSERT INTO complaint_parties (complaint_id, role, resident_id, full_name, sort_order, created_by_type)
SELECT c.id, 'complainant', c.resident_id, LEFT(TRIM(c.resident_name), 150), 0, 'system'
FROM complaints c
WHERE NOT EXISTS (SELECT 1 FROM complaint_parties p
                  WHERE p.complaint_id = c.id AND p.role = 'complainant')
-- BACKFILL END
;

-- ----------------------------------------------------------------------------
-- Mediation calendar (complaint revision Phase 6)
-- complaints.case_no (above) is BC-YYYY-NNNN, assigned on the first scheduling.
-- complaint_hearing_guard has one row; every hearing write locks it first.
-- All hearing DATETIMEs are Asia/Manila local time written by PHP.
-- Same DDL as project-support/migrations/20261118_complaint_hearings.sql.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `complaint_case_counters` (
  `year` SMALLINT NOT NULL,
  `last_no` INT NOT NULL,
  PRIMARY KEY (`year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `complaint_hearing_guard` (
  `id` TINYINT NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO complaint_hearing_guard (id) VALUES (1);

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

-- ----------------------------------------------------------------------------
-- complaint_documents (complaint revision Phase 7): generated summons files
-- generated_documents/complaints/<file_uuid>.pdf / .docx. One 'current' row per
-- hearing + respondent (active_key). hearing_id / party_id FKs must stay
-- ON DELETE RESTRICT (base columns of a stored generated column).
-- fk_cdoc_version is added by a guarded ALTER so this table can be created
-- before document_template_versions exists. generated_at is Manila time from PHP.
-- Same DDL as project-support/migrations/20261119_summons.sql.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `complaint_documents` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `complaint_id` INT NOT NULL,
  `hearing_id` INT NOT NULL,
  `party_id` INT NOT NULL,
  `kind` ENUM('summons') NOT NULL DEFAULT 'summons',
  `template_version_id` INT NULL DEFAULT NULL,
  `file_uuid` CHAR(36) NOT NULL,
  `pdf_sha256` CHAR(64) NOT NULL,
  `docx_sha256` CHAR(64) NOT NULL,
  `status` ENUM('current','superseded') NOT NULL DEFAULT 'current',
  `generated_by` INT NOT NULL,
  `generated_at` DATETIME NOT NULL,
  `active_key` VARCHAR(40) AS (IF(`status` = 'current', CONCAT(`hearing_id`, ':', `party_id`), NULL)) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cdoc_file` (`file_uuid`),
  UNIQUE KEY `uq_cdoc_active` (`active_key`),
  KEY `idx_cdoc_complaint` (`complaint_id`),
  KEY `idx_cdoc_hearing_party` (`hearing_id`, `party_id`),
  KEY `idx_cdoc_party` (`party_id`),
  KEY `idx_cdoc_version` (`template_version_id`),
  KEY `idx_cdoc_generated_by` (`generated_by`),
  CONSTRAINT `fk_cdoc_complaint` FOREIGN KEY (`complaint_id`) REFERENCES `complaints`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_cdoc_hearing` FOREIGN KEY (`hearing_id`) REFERENCES `complaint_hearings`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_cdoc_party` FOREIGN KEY (`party_id`) REFERENCES `complaint_parties`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_cdoc_generated_by` FOREIGN KEY (`generated_by`) REFERENCES `barangay_officials`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.tables
                WHERE table_schema = DATABASE() AND table_name = 'document_template_versions') = 1
           AND (SELECT COUNT(*) FROM information_schema.table_constraints
                WHERE constraint_schema = DATABASE() AND table_name = 'complaint_documents'
                  AND constraint_name = 'fk_cdoc_version' AND constraint_type = 'FOREIGN KEY') = 0,
  'ALTER TABLE complaint_documents ADD CONSTRAINT fk_cdoc_version FOREIGN KEY (template_version_id) REFERENCES document_template_versions(id) ON DELETE RESTRICT',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------------------------
-- Complaint billing (complaint revision Phase 10). default_fee is in the
-- complaint_types CREATE above; the guarded ALTER below only matters when this
-- file is re-imported over an older database.
-- Same DDL as project-support/migrations/20261120_complaint_billing.sql.
-- ----------------------------------------------------------------------------
-- ---------------------------------------------------------------------------
-- complaint_types.default_fee: prefilled amount when staff issue a bill. 0 = no fee.
-- ---------------------------------------------------------------------------
SET @sql := IF((SELECT COUNT(*) FROM information_schema.tables
                WHERE table_schema = DATABASE() AND table_name = 'complaint_types') = 1
           AND (SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE() AND table_name = 'complaint_types' AND column_name = 'default_fee') = 0,
  'ALTER TABLE complaint_types ADD COLUMN default_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER respondent_mode',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- complaint_bills: at most one per complaint. unpaid -> paid | waived (final).
-- billing_note is shown to the complainant; receipt_no and waive_reason are officials only.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `complaint_bills` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `complaint_id` INT NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `billing_note` VARCHAR(255) NULL DEFAULT NULL,
  `status` ENUM('unpaid','paid','waived') NOT NULL DEFAULT 'unpaid',
  `receipt_no` VARCHAR(50) NULL DEFAULT NULL,
  `waive_reason` VARCHAR(500) NULL DEFAULT NULL,
  `issued_by` INT NOT NULL,
  `issued_at` DATETIME NOT NULL,
  `settled_by` INT NULL DEFAULT NULL,
  `settled_at` DATETIME NULL DEFAULT NULL,
  `version` INT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bill_complaint` (`complaint_id`),
  KEY `idx_bill_status` (`status`),
  KEY `idx_bill_issued_by` (`issued_by`),
  KEY `idx_bill_settled_by` (`settled_by`),
  CONSTRAINT `chk_bill_amount` CHECK (`amount` > 0 AND `amount` <= 100000.00),
  CONSTRAINT `fk_bill_complaint` FOREIGN KEY (`complaint_id`)
    REFERENCES `complaints`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_bill_issued_by` FOREIGN KEY (`issued_by`)
    REFERENCES `barangay_officials`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_bill_settled_by` FOREIGN KEY (`settled_by`)
    REFERENCES `barangay_officials`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- notifications
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `resident_id` INT DEFAULT NULL,
  `title` VARCHAR(120) NOT NULL,
  `body` VARCHAR(255) NOT NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` VARCHAR(30) NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_notification_resident` FOREIGN KEY (`resident_id`)
    REFERENCES `residents`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- audit_log
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `audit_log` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `official_id` INT DEFAULT NULL,
  `official_name` VARCHAR(100) NOT NULL,
  `action` VARCHAR(100) NOT NULL,
  `target_type` VARCHAR(50) NOT NULL,
  `target_reference` VARCHAR(100) DEFAULT NULL,
  `details` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_audit_official` FOREIGN KEY (`official_id`)
    REFERENCES `barangay_officials`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- announcements
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `announcements` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(255) NOT NULL,
  `content` TEXT NOT NULL,
  `expires_at` DATETIME NULL,
  `is_pinned` TINYINT(1) DEFAULT 0,
  `posted_by` INT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_announcement_official` FOREIGN KEY (`posted_by`)
    REFERENCES `barangay_officials`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- local_services
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `local_services` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `service_name` VARCHAR(255) NOT NULL,
  `category` VARCHAR(100) NOT NULL,
  `contact_number` VARCHAR(20),
  `address` VARCHAR(255),
  `operating_hours` VARCHAR(100),
  `description` TEXT,
  `posted_by` INT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_service_official` FOREIGN KEY (`posted_by`)
    REFERENCES `barangay_officials`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- complaint_updates
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `complaint_updates` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `complaint_id` INT NOT NULL,
  `actor_type` ENUM('resident','official','system') NOT NULL,
  `actor_id` INT NULL,
  `from_status` VARCHAR(30) NULL,
  `to_status` VARCHAR(30) NOT NULL,
  `note` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_complaint_time` (`complaint_id`, `created_at`),
  CONSTRAINT `fk_update_complaint` FOREIGN KEY (`complaint_id`)
    REFERENCES `complaints`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- complaint_comments
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `complaint_comments` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `complaint_id` INT NOT NULL,
  `actor_type` ENUM('resident','official') NOT NULL,
  `actor_id` INT NOT NULL,
  `body` TEXT NOT NULL,
  `deleted_at` DATETIME NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_comment_thread` (`complaint_id`, `deleted_at`, `created_at`, `id`),
  CONSTRAINT `fk_comment_complaint` FOREIGN KEY (`complaint_id`)
    REFERENCES `complaints`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- complaint_attachments
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `complaint_attachments` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `complaint_id` INT NOT NULL,
  `file_path` VARCHAR(255) NOT NULL,
  `mime` VARCHAR(64) NOT NULL,
  `byte_size` INT NOT NULL,
  `uploaded_by_type` ENUM('resident','official') NOT NULL,
  `uploaded_by_id` INT NOT NULL,
  `deleted_at` DATETIME NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_complaint` (`complaint_id`, `deleted_at`),
  CONSTRAINT `fk_attach_complaint` FOREIGN KEY (`complaint_id`)
    REFERENCES `complaints`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- sms_log
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sms_log` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `resident_id` INT NULL,
  `official_id` INT NULL,
  `endpoint` VARCHAR(64) NOT NULL,
  `to_number` VARCHAR(20) NOT NULL DEFAULT '',
  `status` ENUM('sent','failed','skipped') NOT NULL,
  `reason` VARCHAR(255) NULL,
  `http_message_id` VARCHAR(64) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_resident_time` (`resident_id`, `created_at`),
  KEY `idx_endpoint_time` (`endpoint`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- resident_sms_preferences
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `resident_sms_preferences` (
  `resident_id` INT NOT NULL,
  `complaints_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `requests_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `announcements_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`resident_id`),
  CONSTRAINT `fk_sms_pref_resident` FOREIGN KEY (`resident_id`)
    REFERENCES `residents`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- household_ai_reports
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `household_ai_reports` (
  `household_id` INT NOT NULL,
  `summary` TEXT NOT NULL,
  `key_deprivations_json` JSON NULL,
  `data_gaps_json` JSON NULL,
  `suggested_followups_json` JSON NULL,
  `model` VARCHAR(80) NULL,
  `generated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`household_id`),
  CONSTRAINT `fk_ai_report_household` FOREIGN KEY (`household_id`)
    REFERENCES `households`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- ai_request_log
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ai_request_log` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `official_id` INT NOT NULL,
  `endpoint` VARCHAR(64) NOT NULL,
  `household_count` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_official_time` (`official_id`, `created_at`),
  CONSTRAINT `fk_ai_log_official` FOREIGN KEY (`official_id`)
    REFERENCES `barangay_officials`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- password_reset_throttle (20261121_contact_unique.sql)
-- One row per recovery request. scope 'ip': SHA-256 hash of the client IP;
-- scope 'account': SHA2('<account_type>:<account_id>', 256). Computed in PHP.
-- No raw identifier or IP is ever stored.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `password_reset_throttle` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `scope` ENUM('ip','account') NOT NULL,
  `key_hash` CHAR(64) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_scope_key_time` (`scope`, `key_hash`, `created_at`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ----------------------------------------------------------------------------
-- Seed: default admin account
-- CHANGE BEFORE PRODUCTION. Test-environment default.
-- Username: choogi. The password is not stored in this repository.
-- To set your own before importing, generate a hash with
--   php -r "echo password_hash('your-password', PASSWORD_DEFAULT);"
-- and replace the bcrypt value below.
-- ----------------------------------------------------------------------------
INSERT INTO `barangay_officials`
  (`fname`, `lname`, `username`, `role`, `address`, `contact`,
   `password`, `position`, `status`, `created_at`)
VALUES
  ('Admin', 'Choogi', 'choogi', 'admin', 'Purok 1, Barangay Sampaguita',
   '+639999999999',
   '$2y$10$BwXZrKfqrrUclpQCpARFaOmufBIdyKI5oN2vPhBLakvWkR0F6E.Ui',
   'Barangay Captain', 'active', CURRENT_TIMESTAMP)
ON DUPLICATE KEY UPDATE `username` = `username`;

CREATE TABLE IF NOT EXISTS request_operations (
  operation_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  official_id INT NOT NULL,
  official_name VARCHAR(110) NOT NULL,
  action VARCHAR(30) NOT NULL,
  request_key VARCHAR(50) NOT NULL,
  fingerprint CHAR(64) CHARACTER SET ascii NOT NULL,
  payload_json JSON NOT NULL,
  state ENUM('running','done') NOT NULL DEFAULT 'running',
  http_status SMALLINT NULL,
  outcome_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  KEY request_operation_recovery (state, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS request_notification_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id INT NOT NULL,
  readiness_cycle INT UNSIGNED NOT NULL,
  sequence_no INT UNSIGNED NOT NULL,
  operation_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  resident_id INT NOT NULL,
  contact VARCHAR(30) NOT NULL DEFAULT '',
  message TEXT NOT NULL,
  state ENUM('queued','sending','sent','skipped','failed','uncertain') NOT NULL DEFAULT 'queued',
  reason VARCHAR(255) NOT NULL DEFAULT '',
  retry_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  claimed_at DATETIME NULL,
  finished_at DATETIME NULL,
  UNIQUE KEY request_cycle_notice (request_id, readiness_cycle, sequence_no),
  UNIQUE KEY notification_operation (operation_id),
  KEY notification_dispatch (state, id),
  KEY notification_retry (state, retry_count, finished_at),
  CONSTRAINT fk_notice_request FOREIGN KEY (request_id) REFERENCES document_requests(id),
  CONSTRAINT fk_notice_operation FOREIGN KEY (operation_id) REFERENCES request_operations(operation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Prior Ready records have no reliable per-request delivery ledger. Preserve an
-- uncertain initial intent so rollout cannot silently duplicate a historical SMS.
INSERT INTO request_operations
 (operation_id,official_id,official_name,action,request_key,fingerprint,payload_json,state,http_status,outcome_json,finished_at)
SELECT UUID(),0,'Migration','legacy_notice',reference_no,SHA2(reference_no,256),JSON_OBJECT('migration',true),'done',200,
 JSON_OBJECT('success',true,'changed',false),NOW() FROM document_requests WHERE status='ready'
 AND NOT EXISTS (SELECT 1 FROM request_operations x WHERE x.action='legacy_notice' AND x.request_key=document_requests.reference_no);
INSERT INTO request_notification_events
 (request_id,readiness_cycle,sequence_no,operation_id,resident_id,contact,message,state,reason,finished_at)
SELECT r.id,r.readiness_cycle,0,o.operation_id,r.resident_id,'','Legacy Ready request','uncertain',
 'Pre-migration delivery history is unknown. Reconcile or explicitly resend.',NOW()
 FROM document_requests r JOIN request_operations o ON o.request_key=r.reference_no AND o.action='legacy_notice'
 WHERE r.status='ready'
 AND NOT EXISTS (SELECT 1 FROM request_notification_events e
                 WHERE e.request_id=r.id AND e.readiness_cycle=r.readiness_cycle AND e.sequence_no=0);
