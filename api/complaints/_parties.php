<?php
/**
 * BarangayLink - Complaint parties: complainants and respondents (complaint revision Phase 5).
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * A party is either a REGISTERED resident (resident_id; address and contact are
 * read live from residents) or a person who is NOT registered (typed name,
 * address and contact). full_name is always stored: it is the snapshot used on
 * documents.
 *
 * All rules live here; the endpoints stay thin. Nothing is deleted: a removed
 * party is switched inactive. Every write runs in one transaction, locks the
 * complaint row first and then the party row, checks the caller's version,
 * writes a "Parties updated." timeline note, and after the commit an audit row.
 * Rule failures throw ComplaintProblem, which carries the HTTP status.
 */

require_once __DIR__ . '/_problem.php';
require_once __DIR__ . '/_extra_fields.php';

const COMPLAINT_PARTY_MAX_COMPLAINANTS = 5;
const COMPLAINT_PARTY_MAX_RESPONDENTS = 10;
const COMPLAINT_PARTY_MAX_RESIDENT_RESPONDENTS = 5;
const COMPLAINT_PARTY_ROLES = ['complainant', 'respondent'];
const COMPLAINT_PARTY_MSG_STALE = 'This item was changed by someone else. Reload and try again.';
const COMPLAINT_PARTY_MSG_RESOLVED = 'Resolved complaints cannot be changed.';
const COMPLAINT_PARTY_MSG_SAME_ROLE = 'This resident is already listed.';
const COMPLAINT_PARTY_MSG_BOTH_ROLES = 'The same resident cannot be both a complainant and a respondent.';
const COMPLAINT_PARTY_MSG_NO_RESPONDENTS = 'Respondents cannot be added for this type of concern.';
const COMPLAINT_PARTY_MSG_NEED_RESPONDENT = 'Add at least one person this complaint is about.';
const COMPLAINT_PARTY_MSG_NEED_COMPLAINANT = 'Add at least one complainant.';
const COMPLAINT_PARTY_MSG_INACTIVE_RESIDENT = 'Select an active registered resident.';
const COMPLAINT_PARTY_NOTE = 'Parties updated.';

// ---------------------------------------------------------------------------
// Database helpers
// ---------------------------------------------------------------------------

/** Prepare, bind (int -> i, float -> d, null/anything else -> s) and execute. */
function complaint_party_stmt(mysqli $db, string $sql, array $params = []): mysqli_stmt {
  $stmt = $db->prepare($sql);
  if (!$stmt) throw new RuntimeException($db->error, $db->errno);
  if ($params !== []) {
    $types = '';
    $refs = [];
    foreach (array_values($params) as $index => $value) {
      $types .= is_int($value) ? 'i' : (is_float($value) ? 'd' : 's');
      $refs[$index] = $value;
    }
    $args = [$types];
    foreach ($refs as $index => $_) $args[] = &$refs[$index];
    call_user_func_array([$stmt, 'bind_param'], $args);
  }
  if (!$stmt->execute()) {
    $error = $stmt->error;
    $code = $stmt->errno;
    $stmt->close();
    throw new RuntimeException($error, $code);
  }
  return $stmt;
}

function complaint_party_rows(mysqli $db, string $sql, array $params = []): array {
  $stmt = complaint_party_stmt($db, $sql, $params);
  $result = $stmt->get_result();
  $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
  $stmt->close();
  return $rows;
}

function complaint_party_exec(mysqli $db, string $sql, array $params = []): int {
  $stmt = complaint_party_stmt($db, $sql, $params);
  $affected = (int)$stmt->affected_rows;
  $stmt->close();
  return $affected;
}

/**
 * Run $work in one transaction. A duplicate on uq_party_resident (1062) becomes
 * a 422: two requests raced to add the same resident in the same role.
 */
function complaint_party_transaction(mysqli $db, callable $work): mixed {
  if (!$db->begin_transaction()) throw new RuntimeException($db->error, $db->errno);
  try {
    $result = $work();
    if (!$db->commit()) throw new RuntimeException($db->error, $db->errno);
    return $result;
  } catch (Throwable $error) {
    $db->rollback();
    if (!$error instanceof ComplaintProblem && (int)$error->getCode() === 1062) {
      throw new ComplaintProblem(COMPLAINT_PARTY_MSG_SAME_ROLE, 422);
    }
    throw $error;
  }
}

// ---------------------------------------------------------------------------
// Input validation (pure, except resolving registered residents)
// ---------------------------------------------------------------------------

/** stdClass (from create.php) or an associative array; anything else is refused. */
function complaint_party_input(mixed $raw): array {
  if ($raw instanceof stdClass) return get_object_vars($raw);
  if (is_array($raw) && $raw !== [] && !array_is_list($raw)) return $raw;
  throw new ComplaintProblem('Each person must be given as an object.', 422);
}

function complaint_party_keys(array $input, array $allowed): void {
  foreach (array_keys($input) as $key) {
    if (!in_array($key, $allowed, true)) {
      throw new ComplaintProblem('Unknown field: ' . (is_string($key) ? $key : 'index') . '.', 422);
    }
  }
}

/** Trimmed single-line text of $min..$max characters; no control or direction characters. */
function complaint_party_text(mixed $value, string $what, int $min, int $max): string {
  if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
    throw new ComplaintProblem($what . ' must be text.', 422);
  }
  $value = trim($value);
  if (preg_match('/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{2028}\x{2029}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $value)) {
    throw new ComplaintProblem($what . ' contains characters that are not allowed.', 422);
  }
  $length = mb_strlen($value, 'UTF-8');
  if ($length < $min || $length > $max) {
    throw new ComplaintProblem($min > 0
      ? $what . ' must be ' . $min . ' to ' . $max . ' characters.'
      : $what . ' must be ' . $max . ' characters or fewer.', 422);
  }
  return $value;
}

/**
 * One party, normalized.
 *   registered:   ['resident_id' => int]                       (only when $allowRegistered)
 *   unregistered: ['resident_id' => null, 'full_name', 'address' => ?string, 'contact_no' => ?string]
 * $extraKeys are tolerated (the caller reads them), never returned.
 */
function complaint_party_validate(mixed $raw, bool $allowRegistered, array $extraKeys = []): array {
  $input = complaint_party_input($raw);
  foreach ($extraKeys as $key) unset($input[$key]);
  if (array_key_exists('resident_id', $input)) {
    if (!$allowRegistered) throw new ComplaintProblem('Unknown field: resident_id.', 422);
    complaint_party_keys($input, ['resident_id']);
    $id = $input['resident_id'];
    if (!is_int($id) || $id <= 0) throw new ComplaintProblem('A valid resident_id is required.', 422);
    return ['resident_id' => $id];
  }
  complaint_party_keys($input, ['full_name', 'address', 'contact_no']);
  $address = $input['address'] ?? null;
  $address = $address === null ? '' : complaint_party_text($address, 'Address', 0, 255);
  $contact = $input['contact_no'] ?? null;
  if ($contact !== null && !is_string($contact)) throw new ComplaintProblem('Invalid contact number.', 422);
  $contact = $contact === null ? '' : trim($contact);
  $normalized = null;
  if ($contact !== '') {
    $normalized = normalize_ph_phone($contact);
    if ($normalized === null) throw new ComplaintProblem('Invalid contact number.', 422);
  }
  return [
    'resident_id' => null,
    'full_name' => complaint_party_text($input['full_name'] ?? null, 'Full name', 2, 150),
    'address' => $address === '' ? null : $address,
    'contact_no' => $normalized,
  ];
}

/** A list of parties (null = empty). */
function complaint_party_validate_list(mixed $raw, string $role, bool $allowRegistered, int $max, string $tooMany): array {
  if ($raw === null) return [];
  if (!is_array($raw) || !array_is_list($raw)) {
    throw new ComplaintProblem(($role === 'complainant' ? 'Complainants' : 'Respondents') . ' must be a list.', 422);
  }
  if (count($raw) > $max) throw new ComplaintProblem($tooMany, 422);
  return array_map(static fn($item): array => complaint_party_validate($item, $allowRegistered), $raw);
}

/** An active resident, or 422. Adds the name snapshot and the first name (for SMS). */
function complaint_party_resolve(mysqli $db, array $party): array {
  if ($party['resident_id'] === null) return $party;
  $rows = complaint_party_rows($db, 'SELECT id, fname, lname, status FROM residents WHERE id = ?', [$party['resident_id']]);
  if (!$rows || $rows[0]['status'] !== 'active') throw new ComplaintProblem(COMPLAINT_PARTY_MSG_INACTIVE_RESIDENT, 422);
  $name = trim(trim((string)$rows[0]['fname']) . ' ' . trim((string)$rows[0]['lname']));
  return [
    'resident_id' => (int)$rows[0]['id'],
    'full_name' => mb_substr($name !== '' ? $name : 'Resident', 0, 150, 'UTF-8'),
    'address' => null,
    'contact_no' => null,
    'first_name' => trim((string)$rows[0]['fname']),
  ];
}

/** Respondent mode of a complaint type (none / optional / required). Unknown type: optional. */
function complaint_party_respondent_mode(?int $typeId): string {
  $type = $typeId !== null ? complaint_type_get($typeId, true) : null;
  return $type['respondent_mode'] ?? 'optional';
}

/**
 * The same resident may not appear twice in one role, nor in both roles.
 * $existing: active parties already on the complaint ([role, resident_id]).
 */
function complaint_party_check_resident(array $existing, string $role, ?int $residentId): void {
  if ($residentId === null) return;
  foreach ($existing as $party) {
    if ($party['resident_id'] === null || (int)$party['resident_id'] !== $residentId) continue;
    throw new ComplaintProblem($party['role'] === $role ? COMPLAINT_PARTY_MSG_SAME_ROLE : COMPLAINT_PARTY_MSG_BOTH_ROLES, 422);
  }
}

/** Whole-set rules for a new complaint. */
function complaint_parties_check_set(array $complainants, array $respondents, string $respondentMode): void {
  if ($complainants === []) throw new ComplaintProblem(COMPLAINT_PARTY_MSG_NEED_COMPLAINANT, 422);
  if ($respondentMode === 'none' && $respondents !== []) throw new ComplaintProblem(COMPLAINT_PARTY_MSG_NO_RESPONDENTS, 422);
  if ($respondentMode === 'required' && $respondents === []) throw new ComplaintProblem(COMPLAINT_PARTY_MSG_NEED_RESPONDENT, 422);
  $seen = [];
  foreach (['complainant' => $complainants, 'respondent' => $respondents] as $role => $parties) {
    foreach ($parties as $party) {
      complaint_party_check_resident($seen, $role, $party['resident_id']);
      $seen[] = ['role' => $role, 'resident_id' => $party['resident_id']];
    }
  }
}

// ---------------------------------------------------------------------------
// Inserting the parties of a new complaint (inside the caller's transaction)
// ---------------------------------------------------------------------------

function complaint_parties_insert(mysqli $db, int $complaintId, array $complainants, array $respondents, string $byType, ?int $byId): void {
  foreach (['complainant' => $complainants, 'respondent' => $respondents] as $role => $parties) {
    foreach (array_values($parties) as $index => $party) {
      complaint_party_exec($db,
        'INSERT INTO complaint_parties
           (complaint_id, role, resident_id, full_name, address, contact_no, sort_order, created_by_type, created_by_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$complaintId, $role, $party['resident_id'], $party['full_name'], $party['address'], $party['contact_no'],
         $index, $byType, $byId]);
    }
  }
}

// ---------------------------------------------------------------------------
// Reading
// ---------------------------------------------------------------------------

/**
 * Official view. Registered parties show the resident's CURRENT address and
 * contact; unregistered parties show what was typed.
 */
function complaint_party_official_view(array $row): array {
  $registered = $row['resident_id'] !== null;
  return [
    'id' => (int)$row['id'],
    'role' => (string)$row['role'],
    'resident_id' => $registered ? (int)$row['resident_id'] : null,
    'is_registered' => $registered,
    'full_name' => (string)$row['full_name'],
    'address' => $registered ? ($row['live_address'] === null ? null : (string)$row['live_address'])
                             : ($row['address'] === null ? null : (string)$row['address']),
    'contact_no' => $registered ? ($row['live_contact'] === null ? null : (string)$row['live_contact'])
                                : ($row['contact_no'] === null ? null : (string)$row['contact_no']),
    'sort_order' => (int)$row['sort_order'],
    'version' => (int)$row['version'],
    'is_active' => (int)$row['is_active'] === 1,
  ];
}

/** Resident (filer) view: role and name only. Never address, contact or resident_id. */
function complaint_party_resident_view(array $row): array {
  return ['role' => (string)$row['role'], 'full_name' => (string)$row['full_name']];
}

/** Raw rows for several complaints: complainants first, then respondents, in order. */
function complaint_party_load(mysqli $db, array $complaintIds, bool $activeOnly): array {
  $ids = array_values(array_unique(array_filter(array_map('intval', $complaintIds), static fn(int $id): bool => $id > 0)));
  $rows = [];
  foreach (array_chunk($ids, 500) as $chunk) {
    $marks = implode(',', array_fill(0, count($chunk), '?'));
    $rows = array_merge($rows, complaint_party_rows($db,
      "SELECT p.id, p.complaint_id, p.role, p.resident_id, p.full_name, p.address, p.contact_no,
              p.sort_order, p.version, p.is_active, r.address AS live_address, r.contact AS live_contact
         FROM complaint_parties p
         LEFT JOIN residents r ON r.id = p.resident_id
        WHERE p.complaint_id IN ({$marks})" . ($activeOnly ? ' AND p.is_active = 1' : '') . "
        ORDER BY p.complaint_id, p.is_active DESC, p.role, p.sort_order, p.id", $chunk));
  }
  return $rows;
}

/** Active parties per complaint id, in $view ('official' or 'resident'). */
function complaint_parties_for_complaints(mysqli $db, array $complaintIds, string $view): array {
  $byComplaint = [];
  foreach (complaint_party_load($db, $complaintIds, true) as $row) {
    $byComplaint[(int)$row['complaint_id']][] = $view === 'official'
      ? complaint_party_official_view($row)
      : complaint_party_resident_view($row);
  }
  return $byComplaint;
}

/** Everything for the parties modal: the complaint, the limits, every party (removed ones too). */
function complaint_parties_list(mysqli $db, int $complaintId): array {
  $rows = complaint_party_rows($db,
    'SELECT id, reference_no, status, complaint_type_id, resident_id, filed_by_type FROM complaints WHERE id = ?', [$complaintId]);
  if (!$rows) throw new ComplaintProblem('Complaint not found.', 404);
  $complaint = $rows[0];
  $typeId = $complaint['complaint_type_id'] === null ? null : (int)$complaint['complaint_type_id'];
  return [
    'complaint' => [
      'id' => (int)$complaint['id'],
      'reference_no' => (string)$complaint['reference_no'],
      'status' => (string)$complaint['status'],
      'respondent_mode' => complaint_party_respondent_mode($typeId),
      'filer_resident_id' => $complaint['filed_by_type'] === 'resident' && $complaint['resident_id'] !== null
        ? (int)$complaint['resident_id'] : null,
    ],
    'limits' => ['complainants' => COMPLAINT_PARTY_MAX_COMPLAINANTS, 'respondents' => COMPLAINT_PARTY_MAX_RESPONDENTS],
    'parties' => array_map('complaint_party_official_view', complaint_party_load($db, [$complaintId], false)),
  ];
}

function complaint_party_get(mysqli $db, int $partyId): array {
  $rows = complaint_party_rows($db,
    'SELECT p.id, p.complaint_id, p.role, p.resident_id, p.full_name, p.address, p.contact_no,
            p.sort_order, p.version, p.is_active, r.address AS live_address, r.contact AS live_contact
       FROM complaint_parties p LEFT JOIN residents r ON r.id = p.resident_id WHERE p.id = ?', [$partyId]);
  return complaint_party_official_view($rows[0]);
}

// ---------------------------------------------------------------------------
// Writing (officials)
// ---------------------------------------------------------------------------

/** Locks the complaint; 404 when missing, 409 when resolved. */
function complaint_party_lock_complaint(mysqli $db, int $complaintId): array {
  $rows = complaint_party_rows($db,
    'SELECT id, reference_no, status, complaint_type_id, resident_id, filed_by_type FROM complaints WHERE id = ? FOR UPDATE',
    [$complaintId]);
  if (!$rows) throw new ComplaintProblem('Complaint not found.', 404);
  if ($rows[0]['status'] === 'resolved') throw new ComplaintProblem(COMPLAINT_PARTY_MSG_RESOLVED, 409);
  $rows[0]['respondent_mode'] = complaint_party_respondent_mode(
    $rows[0]['complaint_type_id'] === null ? null : (int)$rows[0]['complaint_type_id']);
  return $rows[0];
}

/** Active parties of a locked complaint, locked too. */
function complaint_party_active(mysqli $db, int $complaintId): array {
  return complaint_party_rows($db,
    'SELECT id, role, resident_id FROM complaint_parties WHERE complaint_id = ? AND is_active = 1 FOR UPDATE', [$complaintId]);
}

/** The complaint id of a party, read before locking (the complaint is always locked first). */
function complaint_party_complaint_id(mysqli $db, int $partyId): int {
  $rows = complaint_party_rows($db, 'SELECT complaint_id FROM complaint_parties WHERE id = ?', [$partyId]);
  if (!$rows) throw new ComplaintProblem('Party not found.', 404);
  return (int)$rows[0]['complaint_id'];
}

/** Locks one party of a locked complaint and checks its version. */
function complaint_party_lock(mysqli $db, int $partyId, int $complaintId, int $version): array {
  $rows = complaint_party_rows($db,
    'SELECT * FROM complaint_parties WHERE id = ? AND complaint_id = ? FOR UPDATE', [$partyId, $complaintId]);
  if (!$rows) throw new ComplaintProblem('Party not found.', 404);
  if ((int)$rows[0]['version'] !== $version) throw new ComplaintProblem(COMPLAINT_PARTY_MSG_STALE, 409);
  if ((int)$rows[0]['is_active'] !== 1) throw new ComplaintProblem('This person was already removed.', 422);
  return $rows[0];
}

function complaint_party_int(array $input, string $key): int {
  $value = $input[$key] ?? null;
  if (!is_int($value) || $value <= 0) throw new ComplaintProblem('A valid ' . $key . ' is required.', 422);
  return $value;
}

function complaint_party_role(mixed $value): string {
  if (!is_string($value) || !in_array($value, COMPLAINT_PARTY_ROLES, true)) {
    throw new ComplaintProblem('Choose complainant or respondent.', 422);
  }
  return $value;
}

/** The timeline note. No names or contact details: residents read the timeline. */
function complaint_party_note(mysqli $db, array $complaint, int $officialId): void {
  complaint_party_exec($db,
    'INSERT INTO complaint_updates (complaint_id, actor_type, actor_id, from_status, to_status, note)
     VALUES (?, ?, ?, ?, ?, ?)',
    [(int)$complaint['id'], 'official', $officialId, (string)$complaint['status'], (string)$complaint['status'], COMPLAINT_PARTY_NOTE]);
}

function complaint_party_audit(mysqli $db, int $officialId, string $officialName, string $action, string $reference, array $details): void {
  $json = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  log_audit($db, $officialId, $officialName, $action, 'complaint_party', mb_substr($reference, 0, 100, 'UTF-8'), $json === false ? '' : $json);
}

/**
 * Add a party. POST {complaint_id, role, resident_id} or {complaint_id, role, full_name, address?, contact_no?}.
 * @return array{changed:bool, party:array}
 */
function complaint_party_add(mysqli $db, array $input, int $officialId, string $officialName): array {
  $complaintId = complaint_party_int($input, 'complaint_id');
  $role = complaint_party_role($input['role'] ?? null);
  $party = complaint_party_validate($input, true, ['complaint_id', 'role']);

  $done = complaint_party_transaction($db, static function () use ($db, $complaintId, $role, $party, $officialId): array {
    $complaint = complaint_party_lock_complaint($db, $complaintId);
    $party = complaint_party_resolve($db, $party);
    if ($role === 'respondent' && $complaint['respondent_mode'] === 'none') {
      throw new ComplaintProblem(COMPLAINT_PARTY_MSG_NO_RESPONDENTS, 422);
    }
    $active = complaint_party_active($db, $complaintId);
    $inRole = count(array_filter($active, static fn(array $row): bool => $row['role'] === $role));
    $max = $role === 'complainant' ? COMPLAINT_PARTY_MAX_COMPLAINANTS : COMPLAINT_PARTY_MAX_RESPONDENTS;
    if ($inRole >= $max) throw new ComplaintProblem('A complaint can have at most ' . $max . ' ' . $role . 's.', 422);
    complaint_party_check_resident($active, $role, $party['resident_id']);
    $sort = (int)complaint_party_rows($db,
      'SELECT COALESCE(MAX(sort_order), -1) + 1 AS n FROM complaint_parties WHERE complaint_id = ? AND role = ?',
      [$complaintId, $role])[0]['n'];
    $stmt = complaint_party_stmt($db,
      'INSERT INTO complaint_parties
         (complaint_id, role, resident_id, full_name, address, contact_no, sort_order, created_by_type, created_by_id)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
      [$complaintId, $role, $party['resident_id'], $party['full_name'], $party['address'], $party['contact_no'],
       $sort, 'official', $officialId]);
    $id = (int)$stmt->insert_id;
    $stmt->close();
    complaint_party_note($db, $complaint, $officialId);
    return ['id' => $id, 'reference' => (string)$complaint['reference_no'],
      'details' => ['party_id' => $id, 'role' => $role, 'registered' => $party['resident_id'] !== null,
                    'resident_id' => $party['resident_id']]];
  });
  complaint_party_audit($db, $officialId, $officialName, 'Added complaint party', $done['reference'], $done['details']);
  return ['changed' => true, 'party' => complaint_party_get($db, $done['id'])];
}

/**
 * Update the name, address or contact of a party that is NOT registered.
 * POST {complaint_id, id, version, role?, full_name, address?, contact_no?}.
 * @return array{changed:bool, party:array}
 */
function complaint_party_update(mysqli $db, array $input, int $officialId, string $officialName): array {
  $complaintId = complaint_party_int($input, 'complaint_id');
  $partyId = complaint_party_int($input, 'id');
  if (!array_key_exists('version', $input)) throw new ComplaintProblem('A valid version is required.', 422);
  $version = complaint_party_int($input, 'version');
  $role = array_key_exists('role', $input) ? complaint_party_role($input['role']) : null;
  if (array_key_exists('resident_id', $input)) {
    throw new ComplaintProblem('Use Link to connect a person to a registered resident.', 422);
  }
  $clean = complaint_party_validate($input, false, ['complaint_id', 'id', 'version', 'role']);

  $done = complaint_party_transaction($db, static function () use ($db, $complaintId, $partyId, $version, $role, $clean, $officialId): array {
    $complaint = complaint_party_lock_complaint($db, $complaintId);
    $row = complaint_party_lock($db, $partyId, $complaintId, $version);
    if ($role !== null && $role !== $row['role']) throw new ComplaintProblem('The role of a person cannot be changed.', 422);
    if ($row['resident_id'] !== null) throw new ComplaintProblem('Registered residents are edited in their profile.', 422);
    $changed = [];
    foreach (['full_name', 'address', 'contact_no'] as $column) {
      if ($row[$column] !== $clean[$column]) $changed[] = $column;
    }
    if ($changed === []) return ['id' => $partyId, 'audit' => null];
    $affected = complaint_party_exec($db,
      'UPDATE complaint_parties SET full_name = ?, address = ?, contact_no = ?, version = version + 1
        WHERE id = ? AND version = ?',
      [$clean['full_name'], $clean['address'], $clean['contact_no'], $partyId, $version]);
    if ($affected !== 1) throw new ComplaintProblem(COMPLAINT_PARTY_MSG_STALE, 409);
    complaint_party_note($db, $complaint, $officialId);
    return ['id' => $partyId, 'reference' => (string)$complaint['reference_no'],
      'audit' => ['party_id' => $partyId, 'role' => $row['role'], 'fields' => $changed]];
  });
  if ($done['audit'] !== null) {
    complaint_party_audit($db, $officialId, $officialName, 'Updated complaint party', $done['reference'], $done['audit']);
  }
  return ['changed' => $done['audit'] !== null, 'party' => complaint_party_get($db, $done['id'])];
}

/**
 * Link a party that is NOT registered to a registered resident. The name
 * becomes the resident's; the typed address and contact are cleared (read live
 * from then on). Same duplicate and both-roles checks as adding.
 * POST {id, version, resident_id}.
 */
function complaint_party_link(mysqli $db, array $input, int $officialId, string $officialName): array {
  complaint_party_keys($input, ['id', 'version', 'resident_id']);
  $partyId = complaint_party_int($input, 'id');
  if (!array_key_exists('version', $input)) throw new ComplaintProblem('A valid version is required.', 422);
  $version = complaint_party_int($input, 'version');
  $residentId = complaint_party_int($input, 'resident_id');
  $complaintId = complaint_party_complaint_id($db, $partyId);

  $done = complaint_party_transaction($db, static function () use ($db, $complaintId, $partyId, $version, $residentId, $officialId): array {
    $complaint = complaint_party_lock_complaint($db, $complaintId);
    $row = complaint_party_lock($db, $partyId, $complaintId, $version);
    if ($row['resident_id'] !== null) throw new ComplaintProblem('This person is already a registered resident.', 422);
    $resident = complaint_party_resolve($db, ['resident_id' => $residentId]);
    $others = array_values(array_filter(complaint_party_active($db, $complaintId),
      static fn(array $party): bool => (int)$party['id'] !== $partyId));
    complaint_party_check_resident($others, (string)$row['role'], $residentId);
    $affected = complaint_party_exec($db,
      'UPDATE complaint_parties SET resident_id = ?, full_name = ?, address = NULL, contact_no = NULL, version = version + 1
        WHERE id = ? AND version = ?',
      [$residentId, $resident['full_name'], $partyId, $version]);
    if ($affected !== 1) throw new ComplaintProblem(COMPLAINT_PARTY_MSG_STALE, 409);
    complaint_party_note($db, $complaint, $officialId);
    return ['reference' => (string)$complaint['reference_no'],
      'details' => ['party_id' => $partyId, 'role' => $row['role'], 'resident_id' => $residentId]];
  });
  complaint_party_audit($db, $officialId, $officialName, 'Linked complaint party', $done['reference'], $done['details']);
  return ['changed' => true, 'party' => complaint_party_get($db, $partyId)];
}

/**
 * Remove (switch inactive) a party. A complaint keeps at least one complainant;
 * a type with respondent_mode "required" keeps at least one respondent; the
 * resident who filed the complaint stays.
 * POST {id, version}.
 */
function complaint_party_remove(mysqli $db, array $input, int $officialId, string $officialName): array {
  complaint_party_keys($input, ['id', 'version']);
  $partyId = complaint_party_int($input, 'id');
  if (!array_key_exists('version', $input)) throw new ComplaintProblem('A valid version is required.', 422);
  $version = complaint_party_int($input, 'version');
  $complaintId = complaint_party_complaint_id($db, $partyId);

  $done = complaint_party_transaction($db, static function () use ($db, $complaintId, $partyId, $version, $officialId): array {
    $complaint = complaint_party_lock_complaint($db, $complaintId);
    $row = complaint_party_lock($db, $partyId, $complaintId, $version);
    if ($row['role'] === 'complainant' && $complaint['filed_by_type'] === 'resident'
        && $row['resident_id'] !== null && $complaint['resident_id'] !== null
        && (int)$row['resident_id'] === (int)$complaint['resident_id']) {
      throw new ComplaintProblem('The resident who filed this complaint cannot be removed.', 422);
    }
    $inRole = count(array_filter(complaint_party_active($db, $complaintId),
      static fn(array $party): bool => $party['role'] === $row['role']));
    if ($row['role'] === 'complainant' && $inRole <= 1) {
      throw new ComplaintProblem('A complaint needs at least one complainant.', 422);
    }
    if ($row['role'] === 'respondent' && $complaint['respondent_mode'] === 'required' && $inRole <= 1) {
      throw new ComplaintProblem('This type of concern needs at least one respondent.', 422);
    }
    $affected = complaint_party_exec($db,
      'UPDATE complaint_parties SET is_active = 0, version = version + 1 WHERE id = ? AND version = ?', [$partyId, $version]);
    if ($affected !== 1) throw new ComplaintProblem(COMPLAINT_PARTY_MSG_STALE, 409);
    complaint_party_note($db, $complaint, $officialId);
    return ['reference' => (string)$complaint['reference_no'],
      'details' => ['party_id' => $partyId, 'role' => $row['role'], 'registered' => $row['resident_id'] !== null]];
  });
  complaint_party_audit($db, $officialId, $officialName, 'Removed complaint party', $done['reference'], $done['details']);
  return ['changed' => true, 'party' => complaint_party_get($db, $partyId)];
}

// ---------------------------------------------------------------------------
// Resident search (officials, for the party editor)
// ---------------------------------------------------------------------------

/** Up to 10 active residents whose full name contains $q. No contact numbers. */
function complaint_party_search_residents(mysqli $db, string $q): array {
  $q = trim($q);
  $length = mb_strlen($q, 'UTF-8');
  if (!mb_check_encoding($q, 'UTF-8') || $length < 2 || $length > 50) {
    throw new ComplaintProblem('Enter 2 to 50 characters.', 422);
  }
  $like = '%' . strtr($q, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
  $rows = complaint_party_rows($db,
    "SELECT id, fname, lname, purok_zone, address FROM residents
      WHERE status = 'active' AND CONCAT(fname, ' ', lname) LIKE ? ESCAPE '\\\\'
      ORDER BY lname, fname, id LIMIT 10", [$like]);
  return array_map(static fn(array $row): array => [
    'id' => (int)$row['id'],
    'full_name' => trim(trim((string)$row['fname']) . ' ' . trim((string)$row['lname'])),
    'purok_zone' => $row['purok_zone'] === null ? null : (string)$row['purok_zone'],
    'address' => (string)$row['address'],
  ], $rows);
}
