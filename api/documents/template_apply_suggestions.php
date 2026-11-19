<?php
/**
 * BarangayLink - Apply confirmed AI placeholder suggestions into a new draft version.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * POST JSON {version_id, analysis_id, selections: [{index, key?}]}.
 * The source version and its file are never modified.
 */
require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/_apply.php';
session_start();
require_official_session();
require_post();

$input = read_json_input();
$versionId = $input['version_id'] ?? null;
$analysisId = $input['analysis_id'] ?? null;
$selections = $input['selections'] ?? null;
if (!is_int($versionId) || $versionId <= 0 || !is_int($analysisId) || $analysisId <= 0 || !is_array($selections)) {
    json_error('A version, an analysis and a list of selections are required.', 422);
}
$officialId = (int)$_SESSION['official_id'];
session_write_close();

$db = get_db();
try {
    $result = document_apply_run($db, $versionId, $analysisId, $selections, $officialId);
} catch (RequestProblem $e) {
    json_error($e->getMessage(), $e->status);
} catch (Throwable $e) {
    error_log('Template apply failed: ' . $e->getMessage());
    json_error('Applying the suggestions failed.', 500);
}
// Server file names and hashes are never returned.
json_success($result);
