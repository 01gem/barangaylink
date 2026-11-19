<?php
/**
 * BarangayLink - Official dashboard figures (KPI tiles and charts).
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * GET ?range=6m|12m|ytd (default 6m). Any official, staff or admin.
 * Every figure comes from dashboard_stats_data() (api/stats/_dashboard.php).
 */

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/_dashboard.php';
session_start();
require_official_session();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_error('Method not allowed.', 405);
session_write_close();

$range = $_GET['range'] ?? '6m';
if (!is_string($range) || !in_array($range, DASHBOARD_RANGES, true)) json_error('Invalid range.', 422);

try {
  $stats = dashboard_stats_data(get_db(), $range);
} catch (Throwable $error) {
  error_log('Dashboard statistics failed: ' . $error->getMessage());
  json_error('Unable to load dashboard statistics.', 500);
}
json_success($stats);
