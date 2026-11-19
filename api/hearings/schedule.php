<?php
/**
 * BarangayLink - Schedule a mediation session (complaint revision Phase 6). Any official (staff or admin).
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * POST {complaint_id, date, start_time, duration_minutes, venue, presiding_official_id, request_key} -> {idempotent, case_no, complaint_status, hearing, sms}.
 */

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../complaints/_community.php';
require_once __DIR__ . '/_hearings.php';
session_start();
require_official_session();
require_post();
$input = complaint_require_json_object();

$db = get_db();
try {
  $result = hearing_schedule($db, $input, (int)$_SESSION['official_id'], (string)($_SESSION['official_name'] ?? ''));
} catch (ComplaintProblem $problem) {
  hearing_fail($problem);
} catch (Throwable $error) {
  json_db_error('Failed to schedule mediation', $error->getMessage());
}
json_success($result);
