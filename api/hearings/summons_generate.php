<?php
/**
 * BarangayLink - Generate (or regenerate) the summons of one respondent for one
 * upcoming scheduled mediation session (complaint revision Phase 7). Any official (staff or admin).
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * POST {hearing_id, party_id} -> {document: {id, hearing_id, party_id, status, generated_at,
 * template_version, view_url, download_url}, superseded_id}.
 * 404 session/party, 409 not upcoming or busy, 422 not an active respondent on this
 * session, 503 signatory/template/conversion. LibreOffice can take several seconds.
 */

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../complaints/_community.php';
require_once __DIR__ . '/_summons.php';
session_start();
require_official_session();
require_post();
$input = complaint_require_json_object();
$officialId = (int)$_SESSION['official_id'];
$officialName = (string)($_SESSION['official_name'] ?? '');
session_write_close();
@set_time_limit(120);

$db = get_db();
try {
  $result = summons_generate($db, $input, $officialId, $officialName);
} catch (ComplaintProblem $problem) {
  hearing_fail($problem);
} catch (Throwable $error) {
  json_db_error('Failed to generate the summons', $error->getMessage());
}
json_success($result);
