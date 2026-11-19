<?php
/**
 * BarangayLink - Add a party, or update a party who is not registered (complaint revision Phase 5). Any official.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * POST {complaint_id, role, resident_id} or {complaint_id, role, full_name, address?, contact_no?}: add.
 * POST {complaint_id, id, version, full_name, address?, contact_no?}: update (not registered only).
 * -> {changed, party}
 */

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/_community.php';
require_once __DIR__ . '/_parties.php';
session_start();
require_official_session();
require_post();
$input = complaint_require_json_object();

$db = get_db();
$officialId = (int)$_SESSION['official_id'];
$officialName = (string)($_SESSION['official_name'] ?? '');
try {
  $saved = array_key_exists('id', $input) ? complaint_party_update($db, $input, $officialId, $officialName) : complaint_party_add($db, $input, $officialId, $officialName);
} catch (ComplaintProblem $problem) {
  json_error($problem->getMessage(), $problem->status);
} catch (Throwable $error) {
  json_db_error('Failed to save complaint party', $error->getMessage());
}
json_success(['changed' => $saved['changed'], 'party' => $saved['party']]);
