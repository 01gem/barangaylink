-- Additive document registry migration. Back up the database before applying.
-- Test on a disposable copy first; this file never selects a database.

CREATE TABLE IF NOT EXISTS document_types (
  id INT NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  template_file VARCHAR(255) NOT NULL,
  status ENUM('active','archived') NOT NULL DEFAULT 'active',
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY document_type_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS document_type_fields (
  id INT NOT NULL AUTO_INCREMENT,
  document_type_id INT NOT NULL,
  field_key VARCHAR(50) NOT NULL,
  label VARCHAR(100) NOT NULL,
  source ENUM('resident_record','resident_supplied','staff_entered','system') NOT NULL,
  data_type ENUM('text','int') NOT NULL DEFAULT 'text',
  min_value INT NULL,
  max_value INT NULL,
  max_length INT NULL,
  is_required TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY document_type_field_key (document_type_id, field_key),
  CONSTRAINT fk_document_type_fields_type
    FOREIGN KEY (document_type_id) REFERENCES document_types(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS document_settings (
  setting_key VARCHAR(100) NOT NULL,
  setting_value VARCHAR(255) NOT NULL,
  updated_by INT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key),
  CONSTRAINT fk_document_settings_official
    FOREIGN KEY (updated_by) REFERENCES barangay_officials(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO document_types (name, template_file, status, sort_order) VALUES
  ('Barangay Clearance', 'barangay_clearance.docx', 'active', 1),
  ('Certificate of Residency', 'certificate_of_residency.docx', 'active', 2),
  ('Certificate of Indigency', 'certificate_of_indigency.docx', 'active', 3),
  ('Certificate of Good Moral Character', 'certificate_of_good_moral_character.docx', 'active', 4),
  ('Business Permit Endorsement', 'business_permit_endorsement.docx', 'active', 5);

INSERT IGNORE INTO document_type_fields
  (document_type_id, field_key, label, source, data_type, min_value, max_value, max_length, is_required, sort_order)
SELECT id, 'full_name', 'Full name', 'resident_record', 'text', NULL, NULL, NULL, 1, 1
FROM document_types WHERE name = 'Barangay Clearance';
INSERT IGNORE INTO document_type_fields
  (document_type_id, field_key, label, source, data_type, min_value, max_value, max_length, is_required, sort_order)
SELECT id, 'age', 'Age', 'resident_supplied', 'int', 0, 120, NULL, 0, 2
FROM document_types WHERE name = 'Barangay Clearance';
INSERT IGNORE INTO document_type_fields
  (document_type_id, field_key, label, source, data_type, min_value, max_value, max_length, is_required, sort_order)
SELECT id, 'civil_status', 'Civil status', 'resident_supplied', 'text', NULL, NULL, 100, 0, 3
FROM document_types WHERE name = 'Barangay Clearance';
INSERT IGNORE INTO document_type_fields
  (document_type_id, field_key, label, source, data_type, min_value, max_value, max_length, is_required, sort_order)
SELECT id, 'address', 'Address', 'resident_record', 'text', NULL, NULL, NULL, 1, 4
FROM document_types WHERE name = 'Barangay Clearance';
INSERT IGNORE INTO document_type_fields
  (document_type_id, field_key, label, source, data_type, min_value, max_value, max_length, is_required, sort_order)
SELECT id, 'purpose', 'Purpose', 'staff_entered', 'text', NULL, NULL, NULL, 1, 5
FROM document_types WHERE name = 'Barangay Clearance';
INSERT IGNORE INTO document_type_fields
  (document_type_id, field_key, label, source, data_type, min_value, max_value, max_length, is_required, sort_order)
SELECT id, 'date_issued', 'Date issued', 'staff_entered', 'text', NULL, NULL, NULL, 1, 6
FROM document_types WHERE name = 'Barangay Clearance';
INSERT IGNORE INTO document_type_fields
  (document_type_id, field_key, label, source, data_type, min_value, max_value, max_length, is_required, sort_order)
SELECT id, 'reference_no', 'Reference number', 'system', 'text', NULL, NULL, NULL, 1, 7
FROM document_types WHERE name = 'Barangay Clearance';
INSERT IGNORE INTO document_type_fields
  (document_type_id, field_key, label, source, data_type, min_value, max_value, max_length, is_required, sort_order)
SELECT id, 'official_name', 'Official name', 'system', 'text', NULL, NULL, NULL, 1, 8
FROM document_types WHERE name = 'Barangay Clearance';

INSERT IGNORE INTO document_type_fields
  (document_type_id, field_key, label, source, data_type, min_value, max_value, max_length, is_required, sort_order)
SELECT id, 'full_name', 'Full name', 'resident_record', 'text', NULL, NULL, NULL, 1, 1
FROM document_types WHERE name = 'Certificate of Residency';
INSERT IGNORE INTO document_type_fields
  (document_type_id, field_key, label, source, data_type, min_value, max_value, max_length, is_required, sort_order)
SELECT id, 'address', 'Address', 'resident_record', 'text', NULL, NULL, NULL, 1, 2
FROM document_types WHERE name = 'Certificate of Residency';
INSERT IGNORE INTO document_type_fields
  (document_type_id, field_key, label, source, data_type, min_value, max_value, max_length, is_required, sort_order)
SELECT id, 'years_of_residency', 'Years of residency', 'resident_supplied', 'int', 0, 120, NULL, 0, 3
FROM document_types WHERE name = 'Certificate of Residency';
INSERT IGNORE INTO document_type_fields
  (document_type_id, field_key, label, source, data_type, min_value, max_value, max_length, is_required, sort_order)
SELECT id, 'purpose', 'Purpose', 'staff_entered', 'text', NULL, NULL, NULL, 1, 4
FROM document_types WHERE name = 'Certificate of Residency';
INSERT IGNORE INTO document_type_fields
  (document_type_id, field_key, label, source, data_type, min_value, max_value, max_length, is_required, sort_order)
SELECT id, 'date_issued', 'Date issued', 'staff_entered', 'text', NULL, NULL, NULL, 1, 5
FROM document_types WHERE name = 'Certificate of Residency';
INSERT IGNORE INTO document_type_fields
  (document_type_id, field_key, label, source, data_type, min_value, max_value, max_length, is_required, sort_order)
SELECT id, 'reference_no', 'Reference number', 'system', 'text', NULL, NULL, NULL, 1, 6
FROM document_types WHERE name = 'Certificate of Residency';
INSERT IGNORE INTO document_type_fields
  (document_type_id, field_key, label, source, data_type, min_value, max_value, max_length, is_required, sort_order)
SELECT id, 'official_name', 'Official name', 'system', 'text', NULL, NULL, NULL, 1, 7
FROM document_types WHERE name = 'Certificate of Residency';

INSERT IGNORE INTO document_type_fields
  (document_type_id, field_key, label, source, data_type, min_value, max_value, max_length, is_required, sort_order)
SELECT dt.id, f.field_key, f.label, f.source, f.data_type, f.min_value, f.max_value, f.max_length, f.is_required, f.sort_order
FROM document_types dt
JOIN (
  SELECT 'Certificate of Indigency' name, 'full_name' field_key, 'Full name' label, 'resident_record' source, 'text' data_type, NULL min_value, NULL max_value, NULL max_length, 1 is_required, 1 sort_order
  UNION ALL SELECT 'Certificate of Indigency', 'address', 'Address', 'resident_record', 'text', NULL, NULL, NULL, 1, 2
  UNION ALL SELECT 'Certificate of Indigency', 'purpose', 'Purpose', 'staff_entered', 'text', NULL, NULL, NULL, 1, 3
  UNION ALL SELECT 'Certificate of Indigency', 'date_issued', 'Date issued', 'staff_entered', 'text', NULL, NULL, NULL, 1, 4
  UNION ALL SELECT 'Certificate of Indigency', 'reference_no', 'Reference number', 'system', 'text', NULL, NULL, NULL, 1, 5
  UNION ALL SELECT 'Certificate of Indigency', 'official_name', 'Official name', 'system', 'text', NULL, NULL, NULL, 1, 6
  UNION ALL SELECT 'Certificate of Good Moral Character', 'full_name', 'Full name', 'resident_record', 'text', NULL, NULL, NULL, 1, 1
  UNION ALL SELECT 'Certificate of Good Moral Character', 'address', 'Address', 'resident_record', 'text', NULL, NULL, NULL, 1, 2
  UNION ALL SELECT 'Certificate of Good Moral Character', 'purpose', 'Purpose', 'staff_entered', 'text', NULL, NULL, NULL, 1, 3
  UNION ALL SELECT 'Certificate of Good Moral Character', 'date_issued', 'Date issued', 'staff_entered', 'text', NULL, NULL, NULL, 1, 4
  UNION ALL SELECT 'Certificate of Good Moral Character', 'reference_no', 'Reference number', 'system', 'text', NULL, NULL, NULL, 1, 5
  UNION ALL SELECT 'Certificate of Good Moral Character', 'official_name', 'Official name', 'system', 'text', NULL, NULL, NULL, 1, 6
  UNION ALL SELECT 'Business Permit Endorsement', 'owner_name', 'Owner name', 'resident_record', 'text', NULL, NULL, NULL, 1, 1
  UNION ALL SELECT 'Business Permit Endorsement', 'business_name', 'Business name', 'resident_supplied', 'text', NULL, NULL, 100, 0, 2
  UNION ALL SELECT 'Business Permit Endorsement', 'business_type', 'Business type', 'resident_supplied', 'text', NULL, NULL, 100, 0, 3
  UNION ALL SELECT 'Business Permit Endorsement', 'business_address', 'Business address', 'resident_record', 'text', NULL, NULL, NULL, 1, 4
  UNION ALL SELECT 'Business Permit Endorsement', 'purpose', 'Purpose', 'staff_entered', 'text', NULL, NULL, NULL, 1, 5
  UNION ALL SELECT 'Business Permit Endorsement', 'date_issued', 'Date issued', 'staff_entered', 'text', NULL, NULL, NULL, 1, 6
  UNION ALL SELECT 'Business Permit Endorsement', 'reference_no', 'Reference number', 'system', 'text', NULL, NULL, NULL, 1, 7
  UNION ALL SELECT 'Business Permit Endorsement', 'official_name', 'Official name', 'system', 'text', NULL, NULL, NULL, 1, 8
) f ON f.name = dt.name
WHERE dt.status = 'active';

INSERT INTO document_settings (setting_key, setting_value)
VALUES ('captain_name', 'Erwin Astronomo')
ON DUPLICATE KEY UPDATE setting_value = setting_value;
