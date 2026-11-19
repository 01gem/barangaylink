<?php
require_once __DIR__ . '/../common.php';
session_start();
require_official_admin();
require_post();
$input = read_json_input(); $typeId = (int)($input['document_type_id'] ?? 0); $archived = filter_var($input['archived'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
if ($typeId <= 0 || $archived === null) json_error('Document type and archive state are required.', 422);
$db = get_db();
$status = $archived ? 'archived' : 'active';
$check = $db->prepare('SELECT status FROM document_types WHERE id=?');
$check->bind_param('i', $typeId); $check->execute(); $current = $check->get_result()->fetch_assoc(); $check->close();
if (!$current) json_error('Document type not found.', 404);
if ($current['status'] === $status) json_error('Document type is already ' . $status . '.', 409);
$stmt = $db->prepare('UPDATE document_types SET status=? WHERE id=?'); $stmt->bind_param('si', $status, $typeId);
if (!$stmt->execute()) json_db_error('Failed to update document type', $stmt->error);
$changed = $stmt->affected_rows; $stmt->close();
if ($changed !== 1) json_error('Document type was not changed.', 409);
log_audit($db, (int)$_SESSION['official_id'], (string)($_SESSION['official_name'] ?? ''), $archived ? 'Archived document type' : 'Restored document type', 'document_type', (string)$typeId);
json_success(['status' => $status]);
