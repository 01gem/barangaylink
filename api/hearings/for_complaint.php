<?php
/**
 * BarangayLink - Mediation sessions of one complaint, official view (complaint revision Phase 6). Any official (staff or admin).
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * GET ?complaint_id=N -> {complaint: {id, reference_no, case_no, status, type_name, can_schedule, schedule_blocker},
 * now, sessions: [official view + attendance (party names) + added_after (+ summons {issued, total} on
 * scheduled sessions once the 20261119 migration ran)]}. Private notes and change
 * reasons are included: officials only.
 */

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/../complaints/_community.php';
require_once __DIR__ . '/_summons.php';
session_start();
require_official_session();
hearing_require_get();

$complaintId = hearing_query_id($_GET['complaint_id'] ?? null);
if ($complaintId === null) json_error('Complaint not found.', 404);

try {
  $db = get_db();
  $result = hearing_for_complaint($db, $complaintId);
  // Phase 7 (read only): summons {issued, total} on each scheduled session, once migrated.
  if (summons_ready($db)) {
    $counts = summons_counts($db, $complaintId);
    foreach ($result['sessions'] as &$session) {
      if ($session['status'] === 'scheduled') $session['summons'] = $counts[$session['id']] ?? ['issued' => 0, 'total' => 0];
    }
    unset($session);
  }
} catch (ComplaintProblem $problem) {
  hearing_fail($problem);
} catch (Throwable $error) {
  json_db_error('Failed to load mediation sessions', $error->getMessage());
}
json_success($result);
