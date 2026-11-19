<?php
require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/_templates.php';
session_start();
require_official_session();
require_post();

$db = get_db();
$typeId = (int)($_POST['document_type_id'] ?? 0);
$notes = trim((string)($_POST['notes'] ?? ''));
if ($typeId <= 0 || mb_strlen($notes) > 255) json_error('Document type and valid notes are required.', 422);
$file = $_FILES['file'] ?? [];
$destination = null;
$committed = false;
try {
    document_template_validate_upload($file);
    $db->begin_transaction();
    $stmt = $db->prepare('SELECT * FROM document_types WHERE id=? FOR UPDATE');
    $stmt->bind_param('i', $typeId); $stmt->execute(); $type = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$type) throw new InvalidArgumentException('Document type not found.');
    $stmt = $db->prepare('SELECT COALESCE(MAX(version_no),0)+1 AS next_version FROM document_template_versions WHERE document_type_id=?');
    $stmt->bind_param('i', $typeId); $stmt->execute(); $versionNo = (int)$stmt->get_result()->fetch_assoc()['next_version']; $stmt->close();
    $fileName = document_template_new_file_name($typeId, $versionNo);
    $destination = document_template_root() . DIRECTORY_SEPARATOR . $fileName;
    if (!move_uploaded_file((string)$file['tmp_name'], $destination)) throw new RuntimeException('Unable to store uploaded template.');
    $sha = hash_file('sha256', $destination); $size = filesize($destination) ?: 0;
    $fields = document_registry_fields($db, $typeId);
    $allowed = array_map(static fn(array $f): string => (string)$f['field_key'], $fields);
    $required = array_map(static fn(array $f): string => (string)$f['field_key'], array_filter($fields, static fn(array $f): bool => (int)$f['is_required'] === 1));
    $errors = document_template_check($destination, $allowed, $required);
    $report = json_encode(['errors' => $errors], JSON_UNESCAPED_UNICODE);
    $stmt = $db->prepare('INSERT INTO document_template_versions (document_type_id,version_no,file_name,original_name,sha256,size_bytes,status,notes,check_report,check_ok,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $original = document_template_sanitized_original_name((string)$file['name']); $checkOk = $errors ? 0 : 1; $status = 'draft'; $officialId = (int)$_SESSION['official_id'];
    $stmt->bind_param('iisssisssii', $typeId, $versionNo, $fileName, $original, $sha, $size, $status, $notes, $report, $checkOk, $officialId);
    if (!$stmt->execute()) throw new RuntimeException($stmt->error); $versionId = $stmt->insert_id; $stmt->close();
    $db->commit();
    $committed = true;
    document_template_log($db, 'Uploaded document template version', (string)$versionId, 'Version ' . $versionNo . ' for ' . $type['name']);
    json_success(['version_id' => $versionId, 'check_ok' => (bool)$checkOk, 'errors' => $errors]);
} catch (InvalidArgumentException $e) {
    @$db->rollback();
    if (!$committed && $destination !== null && is_file($destination)) @unlink($destination);
    json_error($e->getMessage(), 422);
} catch (RequestProblem $e) {
    @$db->rollback();
    if (!$committed && $destination !== null && is_file($destination)) @unlink($destination);
    json_error($e->getMessage(), $e->status);
} catch (Throwable $e) {
    @$db->rollback();
    if (!$committed && $destination !== null && is_file($destination)) @unlink($destination);
    error_log('Template upload failed: ' . $e->getMessage()); json_error('Template upload failed.', 500);
}
