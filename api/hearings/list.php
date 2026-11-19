<?php
/**
 * BarangayLink - Mediation sessions in a date range (complaint revision Phase 6). Any official (staff or admin).
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * GET ?from=Y-m-d&to=Y-m-d (at most 62 days) [&status=][&official_id=] -> {from, to, now, hearings}.
 */

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../complaints/_community.php';
require_once __DIR__ . '/_hearings.php';
session_start();
require_official_session();
hearing_require_get();

try {
  $result = hearing_list(get_db(), $_GET);
} catch (ComplaintProblem $problem) {
  hearing_fail($problem);
} catch (Throwable $error) {
  json_db_error('Failed to load mediation sessions', $error->getMessage());
}
json_success($result);
