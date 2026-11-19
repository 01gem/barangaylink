<?php
/**
 * BarangayLink - Summons of one mediation session (complaint revision Phase 7). Any official (staff or admin).
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * GET ?hearing_id=N -> {hearing: {id, session_no, status, starts_at, venue, case_no, upcoming},
 * can_issue, blocker, respondents: [{party_id, full_name, on_session, party_active,
 * current: {id, generated_at, generated_by_name, view_url, download_url} | null,
 * superseded_count, history: [...], can_generate, reason}]}.
 */

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../complaints/_community.php';
require_once __DIR__ . '/_summons.php';
session_start();
require_official_session();
hearing_require_get();

$hearingId = hearing_query_id($_GET['hearing_id'] ?? null);
if ($hearingId === null) json_error('Session not found.', 404);

try {
  $result = summons_list(get_db(), $hearingId);
} catch (ComplaintProblem $problem) {
  hearing_fail($problem);
} catch (Throwable $error) {
  json_db_error('Failed to load summons', $error->getMessage());
}
json_success($result);
