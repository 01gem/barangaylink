<?php
/**
 * BarangayLink - Save manual text edits of a draft template into a new draft version.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * POST JSON {version_id, expected_sha256, edits: [...]}; see _manual_edit.php.
 * Needs no AI and no analysis. The source version and its file are never modified.
 */
require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/_manual_edit.php';
session_start();
require_official_session();
require_post();

$input = read_json_input();
$versionId = $input['version_id'] ?? null;
$expectedSha = $input['expected_sha256'] ?? null;
$edits = $input['edits'] ?? null;
if (!is_int($versionId) || $versionId <= 0 || !is_string($expectedSha) || !preg_match('/^[a-f0-9]{64}$/', $expectedSha) || !is_array($edits)) {
    json_error(DOCUMENT_MANUAL_MSG_SHAPE, 422);
}
$officialId = (int)$_SESSION['official_id'];
session_write_close();

$db = get_db();
try {
    $result = document_manual_edit_run($db, $versionId, $expectedSha, $edits, $officialId);
} catch (RequestProblem $e) {
    if (isset($e->extra['skipped'])) {
        // Skip reasons are fixed server strings; no document text.
        http_response_code($e->status);
        echo json_encode(['success' => false, 'message' => $e->getMessage(), 'skipped' => $e->extra['skipped']]);
        exit;
    }
    json_error($e->getMessage(), $e->status);
} catch (Throwable $e) {
    error_log('Template manual edit failed: ' . $e->getMessage());
    json_error('Saving the manual edits failed.', 500);
}
// Server file names and hashes of the new version are never returned.
json_success($result);
