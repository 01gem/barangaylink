<?php
/**
 * BarangayLink - Scheduled mediation sessions that have ended (complaint revision Phase 6). Any official (staff or admin).
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * GET -> {now, hearings}: every scheduled session with ends_at before now (Manila), oldest first.
 */

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../complaints/_community.php';
require_once __DIR__ . '/_hearings.php';
session_start();
require_official_session();
hearing_require_get();

try {
  $result = hearing_needs_recording(get_db());
} catch (ComplaintProblem $problem) {
  hearing_fail($problem);
} catch (Throwable $error) {
  json_db_error('Failed to load sessions to record', $error->getMessage());
}
json_success($result);
