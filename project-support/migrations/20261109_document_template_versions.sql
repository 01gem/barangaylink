-- Additive document template versioning migration. Back up the database first
-- and test on a disposable copy before applying to a real installation.

CREATE TABLE IF NOT EXISTS document_template_versions (
  id INT NOT NULL AUTO_INCREMENT,
  document_type_id INT NOT NULL,
  version_no INT NOT NULL,
  file_name VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NULL,
  sha256 CHAR(64) NULL,
  size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('draft','active','retired','discarded') NOT NULL DEFAULT 'draft',
  notes VARCHAR(255) NULL,
  check_report TEXT NULL,
  check_ok TINYINT(1) NOT NULL DEFAULT 0,
  tested_sha256 CHAR(64) NULL,
  tested_at DATETIME NULL,
  test_ok TINYINT(1) NOT NULL DEFAULT 0,
  created_by INT NULL,
  activated_by INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  activated_at DATETIME NULL,
  active_flag TINYINT GENERATED ALWAYS AS (IF(status = 'active', 1, NULL)) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY document_template_version_file (file_name),
  UNIQUE KEY document_template_version_number (document_type_id, version_no),
  UNIQUE KEY document_template_one_active (document_type_id, active_flag),
  CONSTRAINT fk_template_versions_type FOREIGN KEY (document_type_id)
    REFERENCES document_types(id) ON DELETE RESTRICT,
  CONSTRAINT fk_template_versions_created_by FOREIGN KEY (created_by)
    REFERENCES barangay_officials(id) ON DELETE SET NULL,
  CONSTRAINT fk_template_versions_activated_by FOREIGN KEY (activated_by)
    REFERENCES barangay_officials(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO document_template_versions
  (document_type_id, version_no, file_name, original_name, status, notes,
   check_ok, test_ok)
SELECT id, 1, template_file, template_file, 'active', 'Initial version', 1, 1
FROM document_types dt
WHERE NOT EXISTS (
  SELECT 1 FROM document_template_versions v
  WHERE v.document_type_id = dt.id
)
-- Phase 7 (20261119): the blank Summons original is never a version;
-- project-support/scripts/build-summons-template.php builds v1.
AND dt.template_file <> 'summon_letter.docx';
