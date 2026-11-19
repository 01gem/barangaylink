<?php
/**
 * BarangayLink - Change an unpaid complaint fee (complaint revision Phase 10). Any official (staff or admin).
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * POST {complaint_id, version, amount, billing_note?} -> {changed, bill, clear, sms}. Unpaid only.
 * Strict JSON (415 / 422). Rules: api/complaints/_billing.php.
 */

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/_community.php';
require_once __DIR__ . '/_billing.php';
session_start();
require_official_session();
require_post();
$input = complaint_require_json_body();

$db = get_db();
try {
  $result = complaint_bill_update($db, $input, (int)$_SESSION['official_id'], (string)($_SESSION['official_name'] ?? ''));
} catch (ComplaintProblem $problem) {
  json_error($problem->getMessage(), $problem->status);
} catch (Throwable $error) {
  json_db_error('Failed to update complaint fee', $error->getMessage());
}
json_success($result);
