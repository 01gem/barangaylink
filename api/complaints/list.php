<?php
/**
 * BarangayLink - Web-based barangay management system for Brgy. Sampaguita.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 * See the LICENSE file in the project root for the full text.
 */

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/_extra_fields.php';
require_once __DIR__ . '/_parties.php';
require_once __DIR__ . '/../hearings/_hearings.php';
require_once __DIR__ . '/_billing.php';
session_start();
enforce_active_session();

$officialSession = !empty($_SESSION['official_id']);
$residentSession = !empty($_SESSION['resident_id']);
if (!$officialSession && !$residentSession) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_error('Method not allowed.', 405);

$db = get_db();

$columns = "c.id, c.resident_id, c.reference_no, c.case_no, c.resident_name, c.filed_by_type, c.category, c.complaint_type_id, c.severity, c.location_text, c.description, c.date_filed, c.status, c.official_note, c.extra_fields_json,
        c.resident_confirmation, c.confirmed_at, c.reopened_at,
        c.assigned_official_id, c.sla_due_at, c.escalated_at,
        CASE
          WHEN a.id IS NULL THEN NULL
          WHEN TRIM(CONCAT(COALESCE(a.fname, ''), ' ', COALESCE(a.lname, ''))) = '' THEN NULL
          ELSE TRIM(CONCAT(COALESCE(a.fname, ''), ' ', COALESCE(a.lname, '')))
        END AS assigned_official_name,
        a.status AS assigned_official_status,
        CASE
          WHEN c.status IN ('open', 'investigating')
           AND c.sla_due_at IS NOT NULL
           AND c.sla_due_at <= NOW()
          THEN 1 ELSE 0
        END AS is_overdue,
        COALESCE((SELECT MAX(u.id) FROM complaint_updates u
                  WHERE u.complaint_id = c.id AND u.to_status = 'resolved'
                    AND COALESCE(u.from_status, '') <> 'resolved'), 0) AS resolution_update_id,
        (SELECT COUNT(*) FROM complaint_attachments photo WHERE photo.complaint_id = c.id AND photo.deleted_at IS NULL) AS attachment_count,
        c.is_public";
if ($officialSession) {
  $sql = "SELECT {$columns},
        c.duplicate_of_id,
        (c.duplicate_of_id IS NOT NULL) AS is_duplicate,
        parent.reference_no AS duplicate_reference_no,
        (SELECT COUNT(*) FROM complaints child WHERE child.duplicate_of_id = c.id) AS duplicate_children_count
        FROM complaints c
        LEFT JOIN barangay_officials a ON a.id = c.assigned_official_id
        LEFT JOIN complaints parent ON parent.id = c.duplicate_of_id
        ORDER BY c.id DESC";
} else {
  $sql = "SELECT {$columns},
        (c.duplicate_of_id IS NOT NULL) AS is_duplicate
        FROM complaints c
        LEFT JOIN barangay_officials a ON a.id = c.assigned_official_id
        WHERE c.resident_id = ?
        ORDER BY c.id DESC";
}
$stmt = $db->prepare($sql);
if (!$stmt) json_error('Failed to prepare complaints query.', 500);
if (!$officialSession) {
  $residentId = (int)$_SESSION['resident_id'];
  $stmt->bind_param('i', $residentId);
}
$rows = db_query_all($stmt);
$stmt->close();

foreach ($rows as &$row) {
  // Inactive types, fields and retired options stay readable for history.
  // The category name is only a fallback for rows that have no type id.
  $row['complaint_type_id'] = $row['complaint_type_id'] === null ? null : (int)$row['complaint_type_id'];
  $type = $row['complaint_type_id'] !== null
    ? complaint_type_get($row['complaint_type_id'], true)
    : complaint_type_by_name((string)$row['category'], true);
  // Current type name (follows renames); category stays the text it was filed with.
  $row['type_name'] = $type !== null ? $type['type_name'] : (string)$row['category'];
  $extraFields = complaint_extra_fields_read($type, $row['extra_fields_json']);
  $row['extra_fields'] = (object)$extraFields;
  $row['extra_fields_display'] = complaint_extra_fields_display($type, $extraFields);
  unset($row['extra_fields_json']);
  $row['is_public'] = (int)$row['is_public'] === 1;
  $row['is_duplicate'] = (int)$row['is_duplicate'] === 1;
  if ($officialSession) {
    $row['duplicate_of_id'] = $row['duplicate_of_id'] === null ? null : (int)$row['duplicate_of_id'];
    $row['duplicate_children_count'] = (int)$row['duplicate_children_count'];
    $row['duplicate_reference_no'] = $row['duplicate_reference_no'] === null
      ? null
      : (string)$row['duplicate_reference_no'];
  }
}
unset($row);

// Parties (complaint revision Phase 5): one query for every listed complaint.
// Officials get the official view; a resident gets names and roles only, and
// only for the complaints listed here, which are their own.
try {
  $partiesByComplaint = complaint_parties_for_complaints($db, array_column($rows, 'id'), $officialSession ? 'official' : 'resident');
} catch (Throwable $error) {
  json_db_error('Failed to load complaint parties', $error->getMessage());
}
// Mediation (complaint revision Phase 6): the filer sees date, venue, status,
// outcome and public summary only; officials load sessions from api/hearings/.
$hearingsByComplaint = [];
if (!$officialSession) {
  try {
    $hearingsByComplaint = hearing_resident_sessions($db, array_column($rows, 'id'));
  } catch (Throwable $error) {
    json_db_error('Failed to load mediation sessions', $error->getMessage());
  }
}
// Billing (complaint revision Phase 10). Officials: amount, status, note, receipt,
// dates, waive reason and the type's default fee. The filer: amount, status, note
// and dates only (no receipt, no reason, no official). No keys before the migration.
try {
  $billing = complaint_billing_for_complaints($db, array_column($rows, 'id'), $officialSession ? 'official' : 'resident');
} catch (Throwable $error) {
  json_db_error('Failed to load complaint fees', $error->getMessage());
}
foreach ($rows as &$row) {
  $row['parties'] = $partiesByComplaint[(int)$row['id']] ?? [];
  if (!$officialSession) $row['hearings'] = $hearingsByComplaint[(int)$row['id']] ?? [];
  if (isset($billing[(int)$row['id']])) {
    $row['bill'] = $billing[(int)$row['id']]['bill'];
    if ($officialSession) $row['type_default_fee'] = $billing[(int)$row['id']]['type_default_fee'];
  }
}
unset($row);

json_success(['complaints' => $rows]);
?>
