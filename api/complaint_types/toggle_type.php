<?php
/**
 * BarangayLink - Activate or deactivate a complaint type. Any official.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * POST {id, version, is_active} -> {changed, type}. Nothing is deleted.
 */

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../complaints/_community.php';
require_once __DIR__ . '/_admin.php';
session_start();
require_official_session();
require_post();
$input = complaint_require_json_object();

$db = get_db();
try {
  $saved = complaint_type_toggle($db, $input, (int)$_SESSION['official_id'], (string)($_SESSION['official_name'] ?? ''));
} catch (ComplaintTypeProblem $problem) {
  json_error($problem->getMessage(), $problem->status);
} catch (Throwable $error) {
  json_db_error('Failed to change complaint type status', $error->getMessage());
}
json_success(['changed' => $saved['changed'], 'type' => $saved['type']]);
