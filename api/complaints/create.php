<?php
/**
 * BarangayLink - Web-based barangay management system for Brgy. Sampaguita.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 * See the LICENSE file in the project root for the full text.
 */

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/_filing.php';
require_once __DIR__ . '/_community.php';
session_start();
enforce_active_session();

if (empty($_SESSION['resident_id'])) {
  http_response_code(401);
  echo json_encode(['success' => false, 'message' => 'Unauthorized']);
  exit;
}

require_post();
// Phase 9: Content-Type application/json (415) and a JSON object (422) before the existing validation.
complaint_require_json_body();

$db = get_db();
$residentId = (int)$_SESSION['resident_id'];
try {
  $input = complaint_filing_read_body();
  $filing = complaint_filing_validate($input, true);
  // Residents name respondents by typing only; resident_id is refused.
  $respondents = complaint_party_validate_list($input['respondents'] ?? null, 'respondent', false,
    COMPLAINT_PARTY_MAX_RESIDENT_RESPONDENTS, 'You can add at most ' . COMPLAINT_PARTY_MAX_RESIDENT_RESPONDENTS . ' people.');
} catch (ComplaintProblem $problem) {
  json_error($problem->getMessage(), $problem->status);
}

$residentName = trim((string)($_SESSION['resident_name'] ?? ''));
if ($residentName === '') {
  json_error('Resident session expired. Please log in again.', 401);
}
// The complainant is the logged-in resident.
$complainant = ['resident_id' => $residentId, 'full_name' => mb_substr($residentName, 0, 150, 'UTF-8'),
                'address' => null, 'contact_no' => null];
try {
  complaint_parties_check_set([$complainant], $respondents, $filing['type']['respondent_mode']);
} catch (ComplaintProblem $problem) {
  json_error($problem->getMessage(), $problem->status);
}

// Complaint, parties and the first timeline row commit together or not at all.
try {
  $created = complaint_party_transaction($db, static function () use ($db, $filing, $residentId, $residentName, $complainant, $respondents): array {
    $created = complaint_filing_insert($db, $filing, [
      'resident_id' => $residentId,
      'resident_name' => $residentName,
      'filed_by_type' => 'resident',
      'filed_by_official_id' => null,
      'official_note' => 'Received. Pending review.',
    ]);
    complaint_parties_insert($db, $created['id'], [$complainant], $respondents, 'resident', $residentId);
    complaint_filing_update_row($db, $created['id'], 'resident', $residentId, 'Complaint filed.');
    return $created;
  });
} catch (ComplaintProblem $problem) {
  json_error($problem->getMessage(), $problem->status);
} catch (Throwable $error) {
  json_db_error('Failed to submit complaint', $error->getMessage());
}
$complaintId = $created['id'];
$ref = $created['reference_no'];

$residentFirstName = trim(explode(' ', $residentName)[0] ?? $residentName);
notify_resident($residentId, 'complaint_filed', complaint_filing_sms_body($residentFirstName, $ref), 'complaints_enabled');

json_success([
    'id'           => $complaintId,
    'reference_no' => $ref,
    'message'      => 'Complaint submitted successfully.',
]);
?>
