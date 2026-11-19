<?php
require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/_templates.php';
session_start();
require_official_session();
require_post();
$input = read_json_input(); $versionId = (int)($input['version_id'] ?? 0);
if ($versionId <= 0) json_error('Version is required.', 422);
try {
    $db = get_db(); $version = document_template_version($db, $versionId);
    if (!$version || $version['status'] === 'discarded') json_error('Template version is unavailable.', 404);
    $token = document_template_test_version($db, $version);
    document_template_log($db, 'Test-rendered document template', (string)$versionId);
    json_success(['preview_token' => $token, 'message' => 'Template test render completed.']);
} catch (RequestProblem $e) { json_error($e->getMessage(), $e->status); }
catch (Throwable $e) { error_log('Template test render failed: ' . $e->getMessage()); json_error('Template test render failed.', 500); }
