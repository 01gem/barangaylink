-- Additive document registry migration. Back up the database before applying.
-- Test on a disposable copy first; this file never selects a database.

INSERT IGNORE INTO document_type_fields
  (document_type_id, field_key, label, source, data_type, min_value, max_value, max_length, is_required, sort_order)
SELECT dt.id, 'official_title', 'Official title', 'system', 'text', NULL, NULL, NULL, 1,
       f.sort_order + 1
FROM document_types dt
JOIN document_type_fields f
  ON f.document_type_id = dt.id
 AND f.field_key = 'official_name'
WHERE NOT EXISTS (
  SELECT 1
  FROM document_type_fields existing
  WHERE existing.document_type_id = dt.id
    AND existing.field_key = 'official_title'
)
-- Phase 7 (20261119): the internal Summons prints its own title line; it has no official_title.
AND dt.name <> 'Summons';
