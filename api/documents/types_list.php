<?php
require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/_templates.php';
require_once __DIR__ . '/_type_create.php';
session_start();
require_official_session();

$db = get_db();
try {
    $types = document_registry_types($db, false);
    // Phase 5A details (code, processing days, requirements) once the migration ran.
    $details = [];
    if (document_types_phase5a_ready($db)) {
        $stmt = $db->prepare('SELECT id, code, processing_days, requirements FROM document_types');
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) $details[(int)$row['id']] = $row;
        $stmt->close();
    }
    foreach ($types as &$type) {
        unset($type['template_file']);
        $detail = $details[(int)$type['id']] ?? null;
        $type['code'] = $detail['code'] ?? null;
        $type['processing_days'] = $detail === null ? null : (int)$detail['processing_days'];
        $type['requirements'] = $detail['requirements'] ?? null;
        $type['fields'] = document_registry_fields($db, (int)$type['id']);
        $stmt = $db->prepare('SELECT v.id, v.version_no, v.file_name, v.original_name, v.sha256, v.status, v.notes, v.check_report, v.check_ok, v.tested_sha256, v.tested_at, v.test_ok, v.size_bytes, v.created_at, v.activated_at, v.created_by, v.activated_by, CONCAT(co.fname, " ", co.lname) AS created_by_name, CONCAT(ao.fname, " ", ao.lname) AS activated_by_name FROM document_template_versions v LEFT JOIN barangay_officials co ON co.id=v.created_by LEFT JOIN barangay_officials ao ON ao.id=v.activated_by WHERE v.document_type_id=? ORDER BY v.version_no DESC');
        $stmt->bind_param('i', $type['id']); $stmt->execute();
        $type['versions'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
        foreach ($type['versions'] as &$version) {
            $report = json_decode((string)($version['check_report'] ?? ''), true);
            $version['check_errors'] = is_array($report['errors'] ?? null) ? $report['errors'] : [];
            unset($version['check_report'], $version['file_name'], $version['sha256'], $version['tested_sha256']);
        }
        unset($version);
        $type['active_version_id'] = null;
        foreach ($type['versions'] as $version) if ($version['status'] === 'active') $type['active_version_id'] = (int)$version['id'];
    }
    unset($type);
} catch (Throwable $e) {
    json_db_error('Failed to list document templates', $e->getMessage());
}
json_success(['types' => $types, 'catalogue' => document_field_catalogue_public(), 'can_create' => document_types_phase5a_ready($db)]);
