<?php
/**
 * BarangayLink - Check a proposed mediation time without saving (complaint revision Phase 6). Any official (staff or admin).
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * GET ?complaint_id&date&start_time&duration_minutes&venue&presiding_official_id[&exclude_hearing_id] -> {ok, problems, conflicts}. Same rules as schedule.php; writes nothing.
 */

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../complaints/_community.php';
require_once __DIR__ . '/_hearings.php';
session_start();
require_official_session();
hearing_require_get();

try {
  $result = hearing_check(get_db(), $_GET);
} catch (ComplaintProblem $problem) {
  hearing_fail($problem);
} catch (Throwable $error) {
  json_db_error('Failed to check mediation time', $error->getMessage());
}
json_success($result);
