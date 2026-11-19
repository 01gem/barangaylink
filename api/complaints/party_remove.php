<?php
/**
 * BarangayLink - Remove (switch inactive) a complaint party (complaint revision Phase 5). Any official.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * POST {id, version} -> {changed, party}. At least one complainant stays; a type with
 * respondent_mode required keeps at least one respondent; the filing resident stays.
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
  $saved = complaint_party_remove($db, $input, $officialId, $officialName);
} catch (ComplaintProblem $problem) {
  json_error($problem->getMessage(), $problem->status);
} catch (Throwable $error) {
  json_db_error('Failed to remove complaint party', $error->getMessage());
}
json_success(['changed' => $saved['changed'], 'party' => $saved['party']]);
