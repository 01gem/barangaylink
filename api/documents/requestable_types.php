<?php
/**
 * BarangayLink - Document types a resident can request right now (Phase 5A).
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * GET, resident or official session. A type is listed only when it is not
 * archived AND has an active template version. Returns only what the request
 * form needs: name, fee, processing_days, requirements.
 */

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/_registry.php';
session_start();
enforce_active_session();
if (empty($_SESSION['official_id']) && empty($_SESSION['resident_id'])) json_error('Unauthorized', 401);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') json_error('Method not allowed.', 405);

$db = get_db();
try {
    $extra = document_types_phase5a_ready($db)
        ? 'dt.processing_days, dt.requirements'
        : 'NULL AS processing_days, NULL AS requirements';
    $stmt = $db->prepare("SELECT dt.name, p.unit_price, {$extra}
        FROM document_types dt
        JOIN document_template_versions v ON v.document_type_id = dt.id AND v.status = 'active'
        LEFT JOIN document_prices p ON p.document_type = dt.name
        WHERE dt.status = 'active' AND " . document_types_resident_sql($db) . "
        ORDER BY dt.sort_order, dt.id");
    if (!$stmt) throw new RuntimeException($db->error);
    if (!$stmt->execute()) { $error = $stmt->error; $stmt->close(); throw new RuntimeException($error); }
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
} catch (Throwable $e) {
    json_db_error('Failed to list requestable document types', $e->getMessage());
}
$types = array_map(static fn(array $row): array => [
    'name' => (string)$row['name'],
    'fee' => $row['unit_price'] === null ? null : (float)$row['unit_price'],
    'processing_days' => $row['processing_days'] === null ? null : (int)$row['processing_days'],
    'requirements' => $row['requirements'] === null ? null : (string)$row['requirements'],
], $rows);
json_success(['types' => $types]);
