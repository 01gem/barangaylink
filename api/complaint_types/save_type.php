<?php
/**
 * BarangayLink - Create or update a complaint type. Any official.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * POST {id?, version?, type_name, type_code?, description?, respondent_mode?, sort_order?, default_fee?}
 * default_fee (Phase 10): a string 'N' / 'N.NN', 0 .. 100000.00; 0 = no fee.
 * -> {changed, type}. No id = create. Update needs id and version.
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
  $saved = complaint_type_save($db, $input, (int)$_SESSION['official_id'], (string)($_SESSION['official_name'] ?? ''));
} catch (ComplaintTypeProblem $problem) {
  json_error($problem->getMessage(), $problem->status);
} catch (Throwable $error) {
  json_db_error('Failed to save complaint type', $error->getMessage());
}
json_success(['changed' => $saved['changed'], 'type' => $saved['type']]);
