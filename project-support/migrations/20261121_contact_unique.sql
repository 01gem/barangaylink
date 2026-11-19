-- Unique contact numbers (password recovery by username or phone, Phase P1).
-- Additive. Back up the database and test on a disposable copy first.
--
-- RUN WITH THE mysql CLIENT ONLY (this file uses DELIMITER), never phpMyAdmin:
--   cmd /c "mysql -u root DBNAME < project-support\migrations\20261121_contact_unique.sql"
-- Run project-support/scripts/audit-contact-duplicates.php DBNAME first; fix any
-- rows it reports by hand. This file never fixes or rewrites data.
--
-- What it does:
--   1. Pre-check (temporary procedure bl_contact_precheck): for every table that
--      does not have contact_key yet, stop with SIGNAL if two rows share a
--      normalized number or a non-blank contact is not a valid PH mobile number.
--      Nothing below runs when the pre-check fails (never use mysql --force).
--      A failed run leaves the procedure behind; the next run drops it first,
--      or remove it with: DROP PROCEDURE IF EXISTS bl_contact_precheck;
--   2. residents / barangay_officials: STORED generated column contact_key
--      (blank -> NULL, otherwise the normalized +639XXXXXXXXX form, otherwise the
--      trimmed raw value) with a UNIQUE KEY. NULL keys never collide, so blank
--      contacts stay allowed. `contact` itself is unchanged (still NOT NULL).
--   3. password_reset_throttle table (used from Phase P3).
-- Safe to re-run: every step is guarded through information_schema.
-- The normalization expression must stay identical in database/database.sql and
-- project-support/scripts/audit-contact-duplicates.php.
-- This file never selects a database.

-- ---------------------------------------------------------------------------
-- 1. Pre-check
-- ---------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS bl_contact_precheck;

DELIMITER $$
CREATE PROCEDURE bl_contact_precheck()
BEGIN
  DECLARE dup_groups INT DEFAULT 0;
  DECLARE bad_values INT DEFAULT 0;
  DECLARE msg VARCHAR(128);

  IF (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 'residents' AND column_name = 'contact_key') = 0 THEN
    SELECT COUNT(*) INTO dup_groups FROM (
      SELECT k FROM (
        SELECT CASE
                 WHEN TRIM(contact) = '' THEN NULL
                 WHEN REGEXP_LIKE(REGEXP_REPLACE(TRIM(contact), '[[:space:]().-]', ''), '^[+]?(63|0)?9[0-9]{9}$')
                   THEN CONCAT('+63', RIGHT(REGEXP_REPLACE(TRIM(contact), '[[:space:]().-]', ''), 10))
                 ELSE TRIM(contact)
               END AS k
          FROM residents
      ) keyed
      WHERE k IS NOT NULL
      GROUP BY k
      HAVING COUNT(*) > 1
    ) dups;
    IF dup_groups > 0 THEN
      SET msg = CONCAT('residents: ', dup_groups, ' duplicate contact group(s). Run the audit script.');
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = msg;
    END IF;

    SELECT COUNT(*) INTO bad_values FROM residents
     WHERE TRIM(contact) <> ''
       AND NOT REGEXP_LIKE(REGEXP_REPLACE(TRIM(contact), '[[:space:]().-]', ''), '^[+]?(63|0)?9[0-9]{9}$');
    IF bad_values > 0 THEN
      SET msg = CONCAT('residents: ', bad_values, ' invalid contact value(s). Run the audit script.');
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = msg;
    END IF;
  END IF;

  IF (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = 'barangay_officials' AND column_name = 'contact_key') = 0 THEN
    SELECT COUNT(*) INTO dup_groups FROM (
      SELECT k FROM (
        SELECT CASE
                 WHEN TRIM(contact) = '' THEN NULL
                 WHEN REGEXP_LIKE(REGEXP_REPLACE(TRIM(contact), '[[:space:]().-]', ''), '^[+]?(63|0)?9[0-9]{9}$')
                   THEN CONCAT('+63', RIGHT(REGEXP_REPLACE(TRIM(contact), '[[:space:]().-]', ''), 10))
                 ELSE TRIM(contact)
               END AS k
          FROM barangay_officials
      ) keyed
      WHERE k IS NOT NULL
      GROUP BY k
      HAVING COUNT(*) > 1
    ) dups;
    IF dup_groups > 0 THEN
      SET msg = CONCAT('barangay_officials: ', dup_groups, ' duplicate contact group(s). Run the audit script.');
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = msg;
    END IF;

    SELECT COUNT(*) INTO bad_values FROM barangay_officials
     WHERE TRIM(contact) <> ''
       AND NOT REGEXP_LIKE(REGEXP_REPLACE(TRIM(contact), '[[:space:]().-]', ''), '^[+]?(63|0)?9[0-9]{9}$');
    IF bad_values > 0 THEN
      SET msg = CONCAT('barangay_officials: ', bad_values, ' invalid contact value(s). Run the audit script.');
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = msg;
    END IF;
  END IF;
END$$
DELIMITER ;

CALL bl_contact_precheck();
DROP PROCEDURE IF EXISTS bl_contact_precheck;

-- ---------------------------------------------------------------------------
-- 2. contact_key + UNIQUE KEY per table
-- ---------------------------------------------------------------------------
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

-- ---------------------------------------------------------------------------
-- 3. password_reset_throttle: one row per recovery request.
--    scope 'ip': key_hash = a SHA-256 hash of the client IP; scope 'account':
--    key_hash = SHA2('<account_type>:<account_id>', 256). Both computed in PHP.
--    No raw identifier or IP is ever stored.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `password_reset_throttle` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `scope` ENUM('ip','account') NOT NULL,
  `key_hash` CHAR(64) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_scope_key_time` (`scope`, `key_hash`, `created_at`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
