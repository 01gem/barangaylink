<?php
/**
 * BarangayLink - AI commentary on the document-request report.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * The figures are request_report_data() (api/stats/_requests_report.php), the
 * same function the report screen uses; the model receives only
 * request_report_ai_payload() of it.
 *
 * Order: session -> data -> no_data -> key check (503) -> throttle (429) -> model.
 * No AI budget is spent when there is nothing to analyze or AI is not configured.
 */

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/_shared.php';
require_once __DIR__ . '/../stats/_requests_report.php';
session_start();
require_official_session();
require_post();

$officialId   = (int)$_SESSION['official_id'];
$officialName = (string)($_SESSION['official_name'] ?? '');
session_write_close();
set_time_limit(90);

$db = get_db();
try {
  $report = request_report_data($db);
} catch (Throwable $error) {
  error_log('Document-request report for AI failed: ' . $error->getMessage());
  json_error('Unable to load document-request totals.', 500);
}

if (empty($report['totals']['request_count'])) {
  json_success(['analysis' => null, 'reason' => 'no_data', 'model' => null]);
}

if (trim((string)(getenv('GEMINI_API_KEY') ?: '')) === '') {
  json_error('AI analysis is not configured.', 503);
}

ai_throttle_or_die($db, $officialId, 'doc_request_report', 1, 200);

$result = request_report_ai_analysis($db, $report, $officialId, $officialName, 'ai_chat');
if (!$result['ok']) json_error($result['error'], 502);

json_success(['analysis' => $result['analysis'], 'model' => $result['model']]);
