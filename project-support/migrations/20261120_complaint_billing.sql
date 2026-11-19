-- Complaint revision Phase 10: complaint billing (one fee per complaint).
-- Additive. Back up the database and test on a disposable copy first.
-- Safe to re-run: the table uses IF NOT EXISTS and the ALTER is guarded through
-- information_schema. Seeded types keep default_fee 0.00 (no fee).
-- Needs Phase 1 (complaint_types). This file never selects a database.
-- issued_at / settled_at are Asia/Manila local time written by PHP
-- (api/complaints/_billing.php); the created_at / updated_at defaults are a backstop only.

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
