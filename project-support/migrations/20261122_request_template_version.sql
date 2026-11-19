-- Record which template version produced a request's generated document.
-- Additive. Back up the database and test on a disposable copy first.
-- Safe to re-run: every ALTER is guarded through information_schema.
-- Existing rows keep NULL (version unknown); nothing is backfilled or rewritten.
-- The foreign key needs 20261109_document_template_versions.sql; without that
-- table the column is still added and the key is skipped.
-- This file never selects a database.

-- ---------------------------------------------------------------------------
-- document_requests.template_version_id: set by api/requests/_generation.php
-- to the version whose file was filled. NULL = generated before this migration.
-- ---------------------------------------------------------------------------
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE() AND table_name = 'document_requests' AND column_name = 'template_version_id') = 0,
  'ALTER TABLE document_requests ADD COLUMN template_version_id INT NULL DEFAULT NULL AFTER generated_document_path',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.statistics
                WHERE table_schema = DATABASE() AND table_name = 'document_requests' AND index_name = 'idx_request_template_version') = 0,
  'ALTER TABLE document_requests ADD KEY idx_request_template_version (template_version_id)',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- template_version_id -> document_template_versions, only when that table exists.
SET @sql := IF((SELECT COUNT(*) FROM information_schema.tables
                WHERE table_schema = DATABASE() AND table_name = 'document_template_versions') = 1
           AND (SELECT COUNT(*) FROM information_schema.table_constraints
                WHERE constraint_schema = DATABASE() AND table_name = 'document_requests'
                  AND constraint_name = 'fk_request_template_version' AND constraint_type = 'FOREIGN KEY') = 0,
  'ALTER TABLE document_requests ADD CONSTRAINT fk_request_template_version FOREIGN KEY (template_version_id) REFERENCES document_template_versions(id) ON DELETE RESTRICT',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
