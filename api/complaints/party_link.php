<?php
/**
 * BarangayLink - Link a party who is not registered to a registered resident (complaint revision Phase 5). Any official.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * POST {id, version, resident_id} -> {changed, party}. The name becomes the resident's;
 * the typed address and contact are cleared and read live from the resident.
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
  $saved = complaint_party_link($db, $input, $officialId, $officialName);
} catch (ComplaintProblem $problem) {
  json_error($problem->getMessage(), $problem->status);
} catch (Throwable $error) {
  json_db_error('Failed to link complaint party', $error->getMessage());
}
json_success(['changed' => $saved['changed'], 'party' => $saved['party']]);
