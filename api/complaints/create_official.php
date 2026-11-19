<?php
/**
 * BarangayLink - File a walk-in complaint at the barangay hall (complaint revision Phase 5). Any official.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * POST {complaint_type_id, location_text, description, severity?, extra_fields?,
 *       complainants: [party, ...] (1..5), respondents?: [party, ...] (0..10)}
 * party = {"resident_id": int} or {"full_name": str, "address": str|null, "contact_no": str|null}
 * -> {id, reference_no, message, sms: [{resident_id, status}]}
 *
 * complaints.resident_id is the first complainant's resident_id when registered,
 * else NULL. The SMS goes only to registered complainants, after the commit.
 */

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/_community.php';
require_once __DIR__ . '/_filing.php';
session_start();
require_official_session();
require_post();

$header = (string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
if (strtolower(trim(explode(';', $header, 2)[0])) !== 'application/json') {
  json_error('Content-Type must be application/json.', 415);
}

$db = get_db();
$officialId = (int)$_SESSION['official_id'];
$officialName = (string)($_SESSION['official_name'] ?? '');

try {
  $input = complaint_filing_read_body();
  complaint_party_keys($input, ['complaint_type_id', 'location_text', 'description', 'severity',
    'extra_fields', 'complainants', 'respondents']);
  $filing = complaint_filing_validate($input, false);
  $complainants = complaint_party_validate_list($input['complainants'] ?? null, 'complainant', true,
    COMPLAINT_PARTY_MAX_COMPLAINANTS, 'A complaint can have at most ' . COMPLAINT_PARTY_MAX_COMPLAINANTS . ' complainants.');
  $respondents = complaint_party_validate_list($input['respondents'] ?? null, 'respondent', true,
    COMPLAINT_PARTY_MAX_RESPONDENTS, 'A complaint can have at most ' . COMPLAINT_PARTY_MAX_RESPONDENTS . ' respondents.');
  $complainants = array_map(static fn(array $party): array => complaint_party_resolve($db, $party), $complainants);
  $respondents = array_map(static fn(array $party): array => complaint_party_resolve($db, $party), $respondents);
  complaint_parties_check_set($complainants, $respondents, $filing['type']['respondent_mode']);
} catch (ComplaintProblem $problem) {
  json_error($problem->getMessage(), $problem->status);
} catch (Throwable $error) {
  json_db_error('Failed to check walk-in complaint', $error->getMessage());
}

$first = $complainants[0];
try {
  $created = complaint_party_transaction($db, static function () use ($db, $filing, $first, $complainants, $respondents, $officialId): array {
    $created = complaint_filing_insert($db, $filing, [
      'resident_id' => $first['resident_id'],
      'resident_name' => $first['full_name'],
      'filed_by_type' => 'official',
      'filed_by_official_id' => $officialId,
      'official_note' => 'Filed at the barangay hall.',
    ]);
    complaint_parties_insert($db, $created['id'], $complainants, $respondents, 'official', $officialId);
    complaint_filing_update_row($db, $created['id'], 'official', $officialId, 'Complaint filed at the barangay hall.');
    return $created;
  });
} catch (ComplaintProblem $problem) {
  json_error($problem->getMessage(), $problem->status);
} catch (Throwable $error) {
  json_db_error('Failed to file walk-in complaint', $error->getMessage());
}

$registered = array_values(array_filter($complainants, static fn(array $party): bool => $party['resident_id'] !== null));
$details = json_encode([
  'complainants' => count($complainants),
  'registered_complainants' => count($registered),
  'respondents' => count($respondents),
]);
log_audit($db, $officialId, $officialName, 'Filed walk-in complaint', 'complaint', $created['reference_no'], $details === false ? '' : $details);

// Only registered complainants have an account, a contact on file and SMS preferences.
$sms = [];
foreach ($registered as $party) {
  $result = notify_resident($party['resident_id'], 'complaint_filed',
    complaint_filing_sms_body((string)($party['first_name'] ?? ''), $created['reference_no']), 'complaints_enabled');
  $sms[] = ['resident_id' => $party['resident_id'], 'status' => (string)($result['status'] ?? 'skipped')];
}

json_success([
  'id' => $created['id'],
  'reference_no' => $created['reference_no'],
  'message' => 'Walk-in complaint filed.',
  'sms' => $sms,
]);
