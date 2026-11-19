<?php
/**
 * BarangayLink - Ask the AI provider to suggest placeholders for a draft template.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * Suggest-only: this endpoint never changes a template file.
 */
require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../ai/_shared.php';
require_once __DIR__ . '/_analysis.php';
session_start();
require_official_session();
require_post();

$input = read_json_input();
if (($input['acknowledge_external_processing'] ?? null) !== true) {
    json_error('Confirm that the document text may be sent to the AI provider.', 422);
}
$versionId = (int)($input['version_id'] ?? 0);
$officialId = (int)$_SESSION['official_id'];
session_write_close();
// One model attempt is capped at 30 s; ai_chat may retry once.
set_time_limit(90);

$db = get_db();
try {
    $version = $versionId > 0 ? document_template_version($db, $versionId) : null;
} catch (Throwable $e) {
    json_db_error('Template analysis lookup failed', $e->getMessage());
}
if (!$version) json_error('Template version is unavailable.', 404);
if ($version['status'] !== 'draft') json_error('Only draft versions can be analyzed.', 409);
if (trim((string)(getenv('GEMINI_API_KEY') ?: '')) === '') {
    json_error('AI analysis is not configured. Mark placeholders manually in Word.', 503);
}

// Size check before the throttle: a document that cannot be analyzed must not
// consume AI budget.
try {
    document_analysis_extract(document_template_version_path((string)$version['file_name']));
} catch (RequestProblem $e) {
    json_error($e->getMessage(), $e->status);
} catch (Throwable $e) {
    error_log('Template analysis extract failed: ' . $e->getMessage());
    json_error('Template analysis failed.', 500);
}

ai_throttle_or_die($db, $officialId, 'template_analyze', 10, 200,
    'AI analysis limit reached for this hour. Try again later.');

try {
    $result = document_analysis_run($db, $version, $officialId);
} catch (RequestProblem $e) {
    // 'busy' = another analysis holds this version's lock; the review screen keys on the code.
    json_error($e->getMessage(), $e->status, $e->kind === 'busy' ? 'analysis_busy' : null);
} catch (Throwable $e) {
    error_log('Template analysis failed: ' . $e->getMessage());
    json_error('Template analysis failed.', 500);
}
if ($result['status'] !== 'completed') {
    json_error('The AI analysis failed. Try again or mark placeholders manually.', 502);
}
json_success($result);
