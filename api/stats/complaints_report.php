<?php
/**
 * BarangayLink - Complaints report totals for officials.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * Every figure comes from complaint_report_data() (api/stats/_complaint_report.php).
 */

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/_complaint_report.php';
session_start();
require_official_session();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_error('Method not allowed.', 405);
session_write_close();

try {
  $report = complaint_report_data(get_db());
} catch (Throwable $error) {
  error_log('Complaints report failed: ' . $error->getMessage());
  json_error('Unable to load the complaints report.', 500);
}
json_success($report);
