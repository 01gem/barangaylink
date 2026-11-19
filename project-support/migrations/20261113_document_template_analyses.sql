-- Additive document template analysis migration (Phase 3, suggest-only).
-- Back up the database first and test on a disposable copy before applying to
-- a real installation. This file never selects a database and is safe to re-run.

CREATE TABLE IF NOT EXISTS document_template_analyses (
  id INT NOT NULL AUTO_INCREMENT,
  version_id INT NOT NULL,
  file_sha256 CHAR(64) NOT NULL,
  status ENUM('completed','failed') NOT NULL,
  model VARCHAR(100) NULL,
  paragraph_count INT NOT NULL DEFAULT 0,
  suggestions MEDIUMTEXT NOT NULL,
  dropped_count INT NOT NULL DEFAULT 0,
  created_by INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_template_analyses_version (version_id, id),
  CONSTRAINT fk_template_analyses_version FOREIGN KEY (version_id)
    REFERENCES document_template_versions(id) ON DELETE CASCADE,
  CONSTRAINT fk_template_analyses_created_by FOREIGN KEY (created_by)
    REFERENCES barangay_officials(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
