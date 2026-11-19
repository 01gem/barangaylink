-- Complaint revision Phase 7: printable Summons for one respondent of one
-- scheduled mediation session. Officials only.
-- Additive. Back up the database and test on a disposable copy first.
-- Safe to re-run: the ALTERs are guarded through information_schema, the type
-- insert checks for an existing row, the field insert uses INSERT IGNORE and
-- the table uses IF NOT EXISTS. Runs on MySQL 8 and MariaDB.
-- Needs 20261115 (document_types.code / processing_days) and 20261118
-- (complaint_hearings). This file never selects a database.
--
-- After this migration run project-support/scripts/build-summons-template.php
-- (dry run first, then --apply): it builds Summons template version 1 from
-- document_templates/summon_letter.docx (which it only reads) and activates it.
-- Until then summons generation answers 503 "Summons template is unavailable."

-- ---------------------------------------------------------------------------
-- document_types.audience: 'internal' types are never shown to or requested by
-- residents (requestable_types, type_fields, prices, request create/generate).
-- ---------------------------------------------------------------------------
SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE() AND table_name = 'document_types' AND column_name = 'audience') = 0,
  "ALTER TABLE document_types ADD COLUMN audience ENUM('resident','internal') NOT NULL DEFAULT 'resident' AFTER status",
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- The Summons type (internal band: sort_order 1000). Inserted only once.
-- template_file stays the blank original until the build script activates v1.
-- ---------------------------------------------------------------------------
INSERT INTO document_types (name, code, template_file, status, audience, sort_order, processing_days)
SELECT 'Summons', 'summons', 'summon_letter.docx', 'active', 'internal', 1000, 0 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM document_types WHERE code = 'summons' OR name = 'Summons');

INSERT IGNORE INTO document_type_fields
  (document_type_id, field_key, label, source, data_type, min_value, max_value, max_length, is_required, sort_order)
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

-- ---------------------------------------------------------------------------
-- complaint_documents: generated summons files (generated_documents/complaints/
-- <file_uuid>.pdf / .docx). One 'current' row per hearing + respondent
-- (active_key). The FKs on hearing_id / party_id must stay ON DELETE RESTRICT:
-- MySQL forbids CASCADE and SET NULL on a base column of a stored generated
-- column. generated_at is Asia/Manila local time written by PHP.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS complaint_documents (
  id INT NOT NULL AUTO_INCREMENT,
  complaint_id INT NOT NULL,
  hearing_id INT NOT NULL,
  party_id INT NOT NULL,
  kind ENUM('summons') NOT NULL DEFAULT 'summons',
  template_version_id INT NULL DEFAULT NULL,
  file_uuid CHAR(36) NOT NULL,
  pdf_sha256 CHAR(64) NOT NULL,
  docx_sha256 CHAR(64) NOT NULL,
  status ENUM('current','superseded') NOT NULL DEFAULT 'current',
  generated_by INT NOT NULL,
  generated_at DATETIME NOT NULL,
  active_key VARCHAR(40) AS (IF(status = 'current', CONCAT(hearing_id, ':', party_id), NULL)) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cdoc_file (file_uuid),
  UNIQUE KEY uq_cdoc_active (active_key),
  KEY idx_cdoc_complaint (complaint_id),
  KEY idx_cdoc_hearing_party (hearing_id, party_id),
  KEY idx_cdoc_party (party_id),
  KEY idx_cdoc_version (template_version_id),
  KEY idx_cdoc_generated_by (generated_by),
  CONSTRAINT fk_cdoc_complaint FOREIGN KEY (complaint_id) REFERENCES complaints(id) ON DELETE RESTRICT,
  CONSTRAINT fk_cdoc_hearing FOREIGN KEY (hearing_id) REFERENCES complaint_hearings(id) ON DELETE RESTRICT,
  CONSTRAINT fk_cdoc_party FOREIGN KEY (party_id) REFERENCES complaint_parties(id) ON DELETE RESTRICT,
  CONSTRAINT fk_cdoc_generated_by FOREIGN KEY (generated_by) REFERENCES barangay_officials(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- template_version_id -> document_template_versions, only when that table exists.
SET @sql := IF((SELECT COUNT(*) FROM information_schema.tables
                WHERE table_schema = DATABASE() AND table_name = 'document_template_versions') = 1
           AND (SELECT COUNT(*) FROM information_schema.table_constraints
                WHERE constraint_schema = DATABASE() AND table_name = 'complaint_documents'
                  AND constraint_name = 'fk_cdoc_version' AND constraint_type = 'FOREIGN KEY') = 0,
  'ALTER TABLE complaint_documents ADD CONSTRAINT fk_cdoc_version FOREIGN KEY (template_version_id) REFERENCES document_template_versions(id) ON DELETE RESTRICT',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
