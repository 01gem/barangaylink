<?php
/**
 * BarangayLink - Create or update an extra field of a complaint type. Any official.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * POST {type_id, id?, version?, field_key?, label, field_type?, is_required?, sort_order?,
 *       max_length?, min_value?, max_value?, options?} -> {changed, field}.
 * No id = create. Update needs id and version. field_key and field_type never change.
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
  $saved = complaint_field_save($db, $input, (int)$_SESSION['official_id'], (string)($_SESSION['official_name'] ?? ''));
} catch (ComplaintTypeProblem $problem) {
  json_error($problem->getMessage(), $problem->status);
} catch (Throwable $error) {
  json_db_error('Failed to save complaint type field', $error->getMessage());
}
json_success(['changed' => $saved['changed'], 'field' => $saved['field']]);
