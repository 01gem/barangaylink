<?php
/**
 * BarangayLink - Document-request report totals for officials.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * Every figure comes from request_report_data() (api/stats/_requests_report.php).
 */

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/_requests_report.php';
session_start();
require_official_session();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_error('Method not allowed.', 405);
session_write_close();

try {
  $report = request_report_data(get_db());
} catch (Throwable $error) {
  error_log('Document-request report failed: ' . $error->getMessage());
  json_error('Unable to load the document-request report.', 500);
}
json_success($report);
