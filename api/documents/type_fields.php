<?php
/**
 * BarangayLink - Field definitions for the request forms (Phase 5B).
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * GET, resident or official session.
 *
 * Resident: only the types a resident can request now (not archived AND an
 * active template version), each with its resident_supplied fields only.
 * No ids, status, source, or any other field source.
 *
 * Official: every type (archived ones still have pending requests to
 * generate), each with its non-system fields, status and has_active_version.
 *
 * No registry table (legacy install): {"registry": false, "types": []}; the
 * forms then use their built-in legacy lists.
 */

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/_registry.php';
session_start();
enforce_active_session();
if (empty($_SESSION['official_id']) && empty($_SESSION['resident_id'])) json_error('Unauthorized', 401);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') json_error('Method not allowed.', 405);
$isOfficial = !empty($_SESSION['official_id']);
session_write_close();

$db = get_db();
try {
    if (!document_registry_table_exists($db)) json_success(['registry' => false, 'types' => []]);

    // Without the versions table (pre-Phase 2 install) every type counts as published.
    $hasActive = document_registry_active_version_table_exists($db)
        ? "EXISTS (SELECT 1 FROM document_template_versions v WHERE v.document_type_id = dt.id AND v.status = 'active')"
        : '1';
    // Phase 7: request forms only. Internal types (Summons) are left out of both views.
    $resident = document_types_resident_sql($db);
    $stmt = $db->prepare("SELECT dt.id, dt.name, dt.status, {$hasActive} AS has_active_version FROM document_types dt WHERE {$resident} ORDER BY dt.sort_order, dt.id");
    if (!$stmt) throw new RuntimeException($db->error);
    if (!$stmt->execute()) { $error = $stmt->error; $stmt->close(); throw new RuntimeException($error); }
    $typeRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

    $sourceFilter = $isOfficial ? "source <> 'system'" : "source = 'resident_supplied'";
    $stmt = $db->prepare("SELECT document_type_id, field_key, label, source, data_type, min_value, max_value, max_length, is_required
        FROM document_type_fields WHERE {$sourceFilter} ORDER BY document_type_id, sort_order, id");
    if (!$stmt) throw new RuntimeException($db->error);
    if (!$stmt->execute()) { $error = $stmt->error; $stmt->close(); throw new RuntimeException($error); }
    $fieldRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
} catch (Throwable $e) {
    json_db_error('Failed to list document type fields', $e->getMessage());
}

$fieldsByType = [];
foreach ($fieldRows as $row) {
    $field = ['field_key' => (string)$row['field_key'], 'label' => (string)$row['label']];
    if ($isOfficial) $field['source'] = (string)$row['source'];
    $field['data_type'] = (string)$row['data_type'];
    $field['min_value'] = $row['min_value'] === null ? null : (int)$row['min_value'];
    $field['max_value'] = $row['max_value'] === null ? null : (int)$row['max_value'];
    $field['max_length'] = $row['max_length'] === null ? null : (int)$row['max_length'];
    $field['is_required'] = (int)$row['is_required'];
    $fieldsByType[(int)$row['document_type_id']][] = $field;
}

$types = [];
foreach ($typeRows as $row) {
    $published = (int)$row['has_active_version'] === 1;
    $fields = $fieldsByType[(int)$row['id']] ?? [];
    if ($isOfficial) {
        $types[] = ['name' => (string)$row['name'], 'status' => (string)$row['status'], 'has_active_version' => $published, 'fields' => $fields];
    } elseif ($row['status'] === 'active' && $published) {
        $types[] = ['name' => (string)$row['name'], 'fields' => $fields];
    }
}
json_success(['registry' => true, 'types' => $types]);
