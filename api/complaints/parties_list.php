<?php
/**
 * BarangayLink - Parties of one complaint, for the parties modal (complaint revision Phase 5). Any official.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * GET ?complaint_id=N -> {complaint, limits, parties}. Removed parties are
 * included with is_active false. Registered parties show live address and contact.
 */

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/_community.php';
require_once __DIR__ . '/_parties.php';
session_start();
require_official_session();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_error('Method not allowed.', 405);
session_write_close();
header('Cache-Control: private, no-store');

$raw = $_GET['complaint_id'] ?? null;
if (!is_string($raw) || !preg_match('/^[1-9][0-9]{0,9}$/D', $raw)) json_error('Complaint not found.', 404);

try {
  $result = complaint_parties_list(get_db(), (int)$raw);
} catch (ComplaintProblem $problem) {
  json_error($problem->getMessage(), $problem->status);
} catch (Throwable $error) {
  json_db_error('Failed to load complaint parties', $error->getMessage());
}
json_success($result);
