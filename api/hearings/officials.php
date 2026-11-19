<?php
/**
 * BarangayLink - Active officials for the presiding select (complaint revision Phase 6). Any official (staff or admin).
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * GET -> {officials: [{id, name, position}]}.
 */

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../complaints/_community.php';
require_once __DIR__ . '/_hearings.php';
session_start();
require_official_session();
hearing_require_get();

try {
  $result = ['officials' => hearing_officials(get_db())];
} catch (ComplaintProblem $problem) {
  hearing_fail($problem);
} catch (Throwable $error) {
  json_db_error('Failed to load officials', $error->getMessage());
}
json_success($result);
