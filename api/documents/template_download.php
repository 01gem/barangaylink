<?php
require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/_templates.php';
session_start();
require_official_session();
$versionId = (int)($_GET['version_id'] ?? 0);
try {
    $db = get_db(); $version = document_template_version($db, $versionId);
    if (!$version) { http_response_code(404); exit; }
    $path = document_template_version_path((string)$version['file_name']);
    document_template_log($db, 'Downloaded document template version', (string)$versionId);
    header_remove('Content-Type'); header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document'); header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-z0-9_-]+/i', '_', $version['type_name']) . '_v' . (int)$version['version_no'] . '.docx"'); header('X-Content-Type-Options: nosniff'); header('Content-Length: ' . filesize($path)); readfile($path);
} catch (Throwable $e) { error_log('Template download failed: ' . $e->getMessage()); http_response_code(404); }
