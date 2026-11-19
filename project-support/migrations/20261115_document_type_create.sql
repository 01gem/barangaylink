-- Phase 5A: add document types from the console.
-- Additive. Back up the database and test on a disposable copy first.
-- Safe to re-run: every change is guarded through information_schema, so the
-- same file runs on MySQL 8 and MariaDB. This file never selects a database.

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
