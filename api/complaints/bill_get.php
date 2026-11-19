<?php
/**
 * BarangayLink - The fee of one complaint, official view (complaint revision Phase 10). Any official.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * GET ?complaint_id=N -> {complaint: {id, reference_no, status, type_name}, default_fee, clear, blocker,
 * can_issue, bill: null | {id, amount, billing_note, status, receipt_no, waive_reason, issued_at,
 * issued_by_name, settled_at, settled_by_name, version}}. 503 before the 20261120 migration.
 */

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/_billing.php';
session_start();
require_official_session();
hearing_require_get();

$complaintId = hearing_query_id($_GET['complaint_id'] ?? null);
if ($complaintId === null) json_error('Complaint not found.', 404);

try {
  $result = complaint_bill_get(get_db(), $complaintId);
} catch (ComplaintProblem $problem) {
  json_error($problem->getMessage(), $problem->status);
} catch (Throwable $error) {
  json_db_error('Failed to load complaint fee', $error->getMessage());
}
json_success($result);
