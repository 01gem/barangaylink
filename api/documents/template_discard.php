<?php
require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/_templates.php';
session_start();
require_official_session();
require_post();
$versionId = (int)(read_json_input()['version_id'] ?? 0);
try {
    $db = get_db(); $version = document_template_version($db, $versionId, true);
    if (!$version || $version['status'] !== 'draft') json_error('Only draft versions can be discarded.', 409);
    $stmt = $db->prepare("UPDATE document_template_versions SET status='discarded' WHERE id=? AND status='draft'"); $stmt->bind_param('i', $versionId); $stmt->execute();
    if ($stmt->affected_rows !== 1) { $stmt->close(); json_error('Draft was already changed. Reload and try again.', 409); }
    $stmt->close();
    document_template_log($db, 'Discarded document template version', (string)$versionId); json_success(['message' => 'Draft discarded.']);
} catch (Throwable $e) { error_log('Template discard failed: ' . $e->getMessage()); json_error('Template discard failed.', 500); }
