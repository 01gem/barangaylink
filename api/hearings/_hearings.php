<?php
/**
 * BarangayLink - Mediation calendar: hearings, attendance, case numbers (complaint revision Phase 6).
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * TIME. Every hearing datetime is Asia/Manila local time stored in DATETIME.
 * PHP's default zone here is UTC and MySQL's NOW() is +08:00 (the OTP bug came
 * from mixing the two). This module never uses NOW(), CURRENT_TIMESTAMP or
 * date(): hearing_now() is the only clock, and every comparison and insert
 * uses its 'Y-m-d H:i:s' strings, including created_at / updated_at and the
 * timeline rows (complaint_updates.created_at is a TIMESTAMP; the session zone
 * is SYSTEM = +08:00, so the Manila string is stored unshifted).
 *
 * LOCKING. Every write runs in one transaction and takes, in this order:
 * complaint_hearing_guard (id 1) FOR UPDATE -> the complaint FOR UPDATE -> the
 * hearing FOR UPDATE (+ version check). Phase 5 party writes lock the
 * complaint, then the party; update_status / notify_resolved lock the
 * complaint, then read hearings FOR SHARE. Same order everywhere: no cycle.
 * Hearing rows only change under the guard, so the overlap reads made after
 * the locks see every committed hearing. The request_key lookup is a locking
 * read so it does not open the read view before the complaint lock.
 *
 * After the commit: audit row, then SMS (registered parties only). Rule
 * failures throw ComplaintProblem (status + optional extra body keys).
 */

require_once __DIR__ . '/../complaints/_problem.php';
require_once __DIR__ . '/../complaints/_parties.php';
require_once __DIR__ . '/../complaints/_billing.php';   // Phase 10 fee gate

const HEARING_TZ = 'Asia/Manila';
const HEARING_DAY_START_MINUTES = 7 * 60;   // 07:00
const HEARING_DAY_END_MINUTES = 19 * 60;    // 19:00 (the session must end by then)
const HEARING_MIN_LEAD_MINUTES = 15;
const HEARING_MAX_DAYS_AHEAD = 365;
const HEARING_MAX_RANGE_DAYS = 62;
const HEARING_STATUSES = ['scheduled', 'completed', 'cancelled', 'rescheduled'];
const HEARING_OUTCOMES = ['settled', 'for_follow_up', 'referred', 'no_agreement'];
const HEARING_OUTCOME_LABELS = ['settled' => 'Settled', 'for_follow_up' => 'For follow-up', 'referred' => 'Referred', 'no_agreement' => 'No agreement'];
const HEARING_ATTENDANCE = ['not_recorded', 'present', 'absent', 'excused'];
const HEARING_SMS_MAX = 160;

const HEARING_MSG_NEED_RESPONDENT = 'Add at least one respondent before scheduling mediation.';
const HEARING_MSG_ALREADY = 'This complaint already has a scheduled session.';
const HEARING_MSG_RESOLVED = 'Resolved complaints cannot be scheduled.';
const HEARING_MSG_RESOLVE_BLOCK = 'Complete or cancel the scheduled mediation first.';
const HEARING_MSG_CONFLICT = 'This time conflicts with another scheduled session.';
const HEARING_MSG_NOT_SCHEDULED = 'Only a scheduled session can be changed.';
const HEARING_MSG_KEY_USED = 'This request was already used for another change.';
const HEARING_MSG_NOT_STARTED = 'A session can be recorded once it has started.';
const HEARING_MSG_STALE = COMPLAINT_PARTY_MSG_STALE;

// ---------------------------------------------------------------------------
// Clock and formatting
// ---------------------------------------------------------------------------

function hearing_tz(): DateTimeZone {
  static $tz = null;
  return $tz ??= new DateTimeZone(HEARING_TZ);
}

/** The only clock of this module. */
function hearing_now(): DateTimeImmutable {
  return new DateTimeImmutable('now', hearing_tz());
}

function hearing_fmt(DateTimeImmutable $value): string {
  return $value->setTimezone(hearing_tz())->format('Y-m-d H:i:s');
}

/** A stored DATETIME (Manila local) back into a DateTimeImmutable. */
function hearing_from_db(string $value): DateTimeImmutable {
  $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, hearing_tz());
  if ($parsed === false) throw new RuntimeException('Invalid stored hearing time.');
  return $parsed;
}

/** "Sat, Nov 28, 2026, 9:00 AM" (SMS and timeline). */
function hearing_when(string $startsAt): string {
  return hearing_from_db($startsAt)->format('D, M j, Y, g:i A');
}

/** Strict Y-m-d in Manila, or null (2026-02-30, 2026-2-3, trailing newline...). */
function hearing_parse_date(mixed $raw): ?DateTimeImmutable {
  if (!is_string($raw) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $raw)) return null;
  $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $raw, hearing_tz());
  $errors = DateTimeImmutable::getLastErrors();
  if ($parsed === false) return null;
  if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) return null;
  return $parsed->format('Y-m-d') === $raw ? $parsed : null;
}

// ---------------------------------------------------------------------------
// Input
// ---------------------------------------------------------------------------

/** Positive int from JSON, else 422. */
function hearing_int(array $input, string $key): int {
  $value = $input[$key] ?? null;
  if (!is_int($value) || $value <= 0) throw new ComplaintProblem('A valid ' . $key . ' is required.', 422);
  return $value;
}

/** Positive int from a query string, or null. */
function hearing_query_id(mixed $raw): ?int {
  return is_string($raw) && preg_match('/^[1-9][0-9]{0,9}$/D', $raw) ? (int)$raw : null;
}

/**
 * Multi-line text: trimmed, CRLF -> LF, no other control or text-direction
 * characters. $min = 0 allows empty (returned as null).
 */
function hearing_text(mixed $value, string $what, int $min, int $max, string $rangeMessage): ?string {
  if ($value === null) return null;
  if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) throw new ComplaintProblem($what . ' must be text.', 422);
  $value = trim(str_replace(["\r\n", "\r"], "\n", $value));
  if (preg_match('/[\x{0000}-\x{0009}\x{000B}-\x{001F}\x{007F}-\x{009F}\x{2028}\x{2029}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $value)) {
    throw new ComplaintProblem($what . ' contains characters that are not allowed.', 422);
  }
  if ($value === '' && $min === 0) return null;
  $length = mb_strlen($value, 'UTF-8');
  if ($length < $min || $length > $max) throw new ComplaintProblem($rangeMessage, 422);
  return $value;
}

function hearing_reason(mixed $value): string {
  $reason = hearing_text($value ?? '', 'Reason', 5, 500, 'Enter a reason (5 to 500 characters).');
  if ($reason === null) throw new ComplaintProblem('Enter a reason (5 to 500 characters).', 422);
  return $reason;
}

function hearing_request_key(mixed $value): string {
  if (!is_string($value) || !preg_match('/^[0-9a-f]{32}$/D', $value)) {
    throw new ComplaintProblem('A valid request_key is required.', 422);
  }
  return $value;
}

/** [venue, venue_key]: the key is lower case with collapsed spaces. */
function hearing_venue(mixed $raw): array {
  if (!is_string($raw) || !mb_check_encoding($raw, 'UTF-8')) throw new ComplaintProblem('Enter a venue (2 to 180 characters).', 422);
  $venue = preg_replace('/\s+/u', ' ', trim($raw)) ?? '';
  if (preg_match('/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{2028}\x{2029}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $venue)) {
    throw new ComplaintProblem('Venue contains characters that are not allowed.', 422);
  }
  $length = mb_strlen($venue, 'UTF-8');
  if ($length < 2 || $length > 180) throw new ComplaintProblem('Enter a venue (2 to 180 characters).', 422);
  return [$venue, mb_strtolower($venue, 'UTF-8')];
}

/**
 * Time rules. Returns ['problems' => [[field, message, status]], 'start' => ?DTI, 'end' => ?DTI].
 * $duration may be an int (JSON) or null when the caller could not read one.
 */
function hearing_slot(mixed $date, mixed $time, mixed $duration, DateTimeImmutable $now): array {
  $problems = [];
  $add = static function (string $field, string $message) use (&$problems): void {
    $problems[] = ['field' => $field, 'message' => $message, 'status' => 422];
  };
  $day = hearing_parse_date($date);
  if ($day === null) $add('date', 'Enter a valid date.');
  $minutes = null;
  if (!is_string($time) || !preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])$/D', $time, $match)) {
    $add('start_time', 'Enter a valid start time.');
  } elseif ((int)$match[2] % 15 !== 0) {
    $add('start_time', 'Start time must be on the quarter hour.');
  } else {
    $minutes = (int)$match[1] * 60 + (int)$match[2];
    if ($minutes < HEARING_DAY_START_MINUTES) $add('start_time', 'Sessions start at 7:00 AM or later.');
  }
  $length = null;
  if (!is_int($duration) || $duration < 15 || $duration > 240 || $duration % 15 !== 0) {
    $add('duration_minutes', 'Duration must be 15 to 240 minutes, in steps of 15.');
  } else {
    $length = $duration;
  }
  if ($day !== null && (int)$day->format('N') === 7) $add('date', 'Mediation is held Monday to Saturday.');
  if ($minutes !== null && $length !== null && $minutes >= HEARING_DAY_START_MINUTES && $minutes + $length > HEARING_DAY_END_MINUTES) {
    $add('duration_minutes', 'The session must end by 7:00 PM.');
  }
  $start = $end = null;
  if ($day !== null && $minutes !== null) {
    $start = $day->setTime(intdiv($minutes, 60), $minutes % 60);
    if ($start < $now->modify('+' . HEARING_MIN_LEAD_MINUTES . ' minutes')) {
      $add('start_time', 'Choose a time at least 15 minutes from now.');
    } elseif ($start > $now->modify('+' . HEARING_MAX_DAYS_AHEAD . ' days')) {
      $add('date', 'Choose a date within the next 12 months.');
    }
    if ($length !== null) $end = $start->modify('+' . $length . ' minutes');
  }
  return ['problems' => $problems, 'start' => $start, 'end' => $end];
}

// ---------------------------------------------------------------------------
// Reading helpers
// ---------------------------------------------------------------------------

function hearing_complaint_row(mysqli $db, int $complaintId, bool $lock): array {
  $rows = complaint_party_rows($db,
    'SELECT id, reference_no, case_no, status, resident_id, resident_confirmation, complaint_type_id, category
       FROM complaints WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''), [$complaintId]);
  if (!$rows) throw new ComplaintProblem('Complaint not found.', 404);
  return $rows[0];
}

/** Active parties (id, role, resident_id). Under the complaint lock in writes. */
function hearing_active_parties(mysqli $db, int $complaintId): array {
  return complaint_party_rows($db,
    'SELECT id, role, resident_id FROM complaint_parties WHERE complaint_id = ? AND is_active = 1 ORDER BY role, sort_order, id',
    [$complaintId]);
}

function hearing_type_name(array $complaint): string {
  $typeId = $complaint['complaint_type_id'] === null ? null : (int)$complaint['complaint_type_id'];
  $type = $typeId !== null ? complaint_type_get($typeId, true) : complaint_type_by_name((string)$complaint['category'], true);
  return $type !== null ? (string)$type['type_name'] : (string)$complaint['category'];
}

/**
 * Complaint-level rules, in the order they are reported: resolved (409),
 * already scheduled (409), fee not paid or waived (409, Phase 10), parties (422).
 */
function hearing_complaint_problems(mysqli $db, array $complaint, array $parties, ?int $excludeHearingId): array {
  $problems = [];
  if ((string)$complaint['status'] === 'resolved') {
    $problems[] = ['field' => 'complaint_id', 'message' => HEARING_MSG_RESOLVED, 'status' => 409];
  }
  $scheduled = complaint_party_rows($db,
    "SELECT id FROM complaint_hearings WHERE complaint_id = ? AND status = 'scheduled' AND id <> ?",
    [(int)$complaint['id'], $excludeHearingId ?? 0]);
  if ($scheduled) $problems[] = ['field' => 'complaint_id', 'message' => HEARING_MSG_ALREADY, 'status' => 409];
  // Phase 10: the fee must be paid or waived (bill read FOR SHARE: after the complaint lock in writes).
  if (!complaint_billing_clear($db, (int)$complaint['id'], true)) {
    $problems[] = ['field' => 'complaint_id', 'message' => BILLING_MSG_GATE, 'status' => 409];
  }
  $roles = array_count_values(array_column($parties, 'role'));
  if (($roles['complainant'] ?? 0) < 1 || ($roles['respondent'] ?? 0) < 1) {
    $problems[] = ['field' => 'complaint_id', 'message' => HEARING_MSG_NEED_RESPONDENT, 'status' => 422];
  }
  return $problems;
}

/** An active official, or null. */
function hearing_presiding(mysqli $db, mixed $id): ?array {
  if (!is_int($id) || $id <= 0) return null;
  $rows = complaint_party_rows($db,
    "SELECT id, fname, lname, position FROM barangay_officials WHERE id = ? AND status = 'active'", [$id]);
  return $rows[0] ?? null;
}

/**
 * Scheduled hearings of OTHER complaints overlapping [start, end) that share
 * the presiding official, the venue key, or a registered resident with this
 * complaint. One entry per (hearing, reason); no names.
 */
function hearing_conflicts(mysqli $db, int $complaintId, array $parties, string $start, string $end, string $venueKey, int $officialId, ?int $excludeId): array {
  $rows = complaint_party_rows($db,
    "SELECT h.id, h.complaint_id, h.starts_at, h.ends_at, h.presiding_official_id, h.venue_key, c.case_no
       FROM complaint_hearings h JOIN complaints c ON c.id = h.complaint_id
      WHERE h.status = 'scheduled' AND h.starts_at < ? AND h.ends_at > ? AND h.complaint_id <> ? AND h.id <> ?
      ORDER BY h.starts_at, h.id",
    [$end, $start, $complaintId, $excludeId ?? 0]);
  if (!$rows) return [];
  $residents = [];
  foreach ($parties as $party) if ($party['resident_id'] !== null) $residents[(int)$party['resident_id']] = true;
  $shared = [];
  if ($residents !== []) {
    $ids = array_map(static fn(array $row): int => (int)$row['id'], $rows);
    $marks = implode(',', array_fill(0, count($ids), '?'));
    // A party of the other hearing: on its attendance, or an active party of its complaint now.
    $partyRows = complaint_party_rows($db,
      "SELECT a.hearing_id, p.resident_id FROM complaint_hearing_attendance a
         JOIN complaint_parties p ON p.id = a.party_id
        WHERE a.hearing_id IN ({$marks}) AND p.resident_id IS NOT NULL
       UNION
       SELECT h.id AS hearing_id, p.resident_id FROM complaint_hearings h
         JOIN complaint_parties p ON p.complaint_id = h.complaint_id AND p.is_active = 1
        WHERE h.id IN ({$marks}) AND p.resident_id IS NOT NULL",
      array_merge($ids, $ids));
    foreach ($partyRows as $partyRow) {
      if (isset($residents[(int)$partyRow['resident_id']])) $shared[(int)$partyRow['hearing_id']] = true;
    }
  }
  $conflicts = [];
  foreach ($rows as $row) {
    $base = [
      'hearing_id' => (int)$row['id'],
      'case_no' => $row['case_no'] === null ? null : (string)$row['case_no'],
      'starts_at' => (string)$row['starts_at'],
      'ends_at' => (string)$row['ends_at'],
    ];
    if ((int)$row['presiding_official_id'] === $officialId) $conflicts[] = $base + ['reason' => 'official'];
    if ((string)$row['venue_key'] === $venueKey) $conflicts[] = $base + ['reason' => 'venue'];
    if (isset($shared[(int)$row['id']])) $conflicts[] = $base + ['reason' => 'party'];
  }
  return $conflicts;
}

/**
 * Every schedule rule for one proposal: shared by check.php (report all) and
 * the writes (throw the first). $fields: date, start_time, duration_minutes,
 * venue, presiding_official_id (raw values).
 */
function hearing_evaluate(mysqli $db, array $complaint, array $parties, array $fields, ?int $excludeId, DateTimeImmutable $now): array {
  $problems = hearing_complaint_problems($db, $complaint, $parties, $excludeId);
  $slot = hearing_slot($fields['date'] ?? null, $fields['start_time'] ?? null, $fields['duration_minutes'] ?? null, $now);
  $problems = array_merge($problems, $slot['problems']);
  $venue = $venueKey = null;
  try {
    [$venue, $venueKey] = hearing_venue($fields['venue'] ?? null);
  } catch (ComplaintProblem $problem) {
    $problems[] = ['field' => 'venue', 'message' => $problem->getMessage(), 'status' => 422];
  }
  $presiding = hearing_presiding($db, $fields['presiding_official_id'] ?? null);
  if ($presiding === null) {
    $problems[] = ['field' => 'presiding_official_id', 'message' => 'Choose an active official to preside.', 'status' => 422];
  }
  $conflicts = [];
  if ($slot['start'] !== null && $slot['end'] !== null && $venueKey !== null && $presiding !== null) {
    $conflicts = hearing_conflicts($db, (int)$complaint['id'], $parties, hearing_fmt($slot['start']), hearing_fmt($slot['end']),
      $venueKey, (int)$presiding['id'], $excludeId);
  }
  return [
    'problems' => $problems, 'conflicts' => $conflicts,
    'start' => $slot['start'], 'end' => $slot['end'],
    'venue' => $venue, 'venue_key' => $venueKey, 'presiding' => $presiding,
  ];
}

/** Writes: the first problem, then conflicts (409 with the list). */
function hearing_enforce(array $evaluation): void {
  if ($evaluation['problems'] !== []) {
    $first = $evaluation['problems'][0];
    throw new ComplaintProblem($first['message'], $first['status']);
  }
  if ($evaluation['conflicts'] !== []) {
    throw new ComplaintProblem(HEARING_MSG_CONFLICT, 409, ['code' => 'hearing_conflict', 'conflicts' => $evaluation['conflicts']]);
  }
}

// ---------------------------------------------------------------------------
// Views
// ---------------------------------------------------------------------------

/** Official view of one hearing row (joined with presiding fname/lname). */
function hearing_official_view(array $row): array {
  return [
    'id' => (int)$row['id'],
    'complaint_id' => (int)$row['complaint_id'],
    'session_no' => (int)$row['session_no'],
    'starts_at' => (string)$row['starts_at'],
    'ends_at' => (string)$row['ends_at'],
    'venue' => (string)$row['venue'],
    'presiding_official_id' => (int)$row['presiding_official_id'],
    'presiding_name' => trim((string)($row['p_fname'] ?? '') . ' ' . (string)($row['p_lname'] ?? '')),
    'status' => (string)$row['status'],
    'outcome' => $row['outcome'] === null ? null : (string)$row['outcome'],
    'public_summary' => $row['public_summary'] === null ? null : (string)$row['public_summary'],
    'private_notes' => $row['private_notes'] === null ? null : (string)$row['private_notes'],
    'change_reason' => $row['change_reason'] === null ? null : (string)$row['change_reason'],
    'rescheduled_from_id' => $row['rescheduled_from_id'] === null ? null : (int)$row['rescheduled_from_id'],
    'completed_at' => $row['completed_at'] === null ? null : (string)$row['completed_at'],
    'version' => (int)$row['version'],
  ];
}

function hearing_get(mysqli $db, int $hearingId): array {
  $rows = complaint_party_rows($db,
    'SELECT h.*, o.fname AS p_fname, o.lname AS p_lname FROM complaint_hearings h
       JOIN barangay_officials o ON o.id = h.presiding_official_id WHERE h.id = ?', [$hearingId]);
  if (!$rows) throw new ComplaintProblem('Session not found.', 404);
  return hearing_official_view($rows[0]);
}

/** Calendar rows (list.php, needs_recording.php). */
function hearing_calendar_rows(mysqli $db, string $where, array $params, DateTimeImmutable $now): array {
  $rows = complaint_party_rows($db,
    "SELECT h.id, h.complaint_id, h.session_no, h.starts_at, h.ends_at, h.venue, h.presiding_official_id, h.status,
            h.outcome, h.version, o.fname AS p_fname, o.lname AS p_lname,
            c.case_no, c.reference_no, c.complaint_type_id, c.category,
            (SELECT COUNT(*) FROM complaint_hearing_attendance a WHERE a.hearing_id = h.id) AS party_count
       FROM complaint_hearings h
       JOIN complaints c ON c.id = h.complaint_id
       JOIN barangay_officials o ON o.id = h.presiding_official_id
      WHERE {$where}
      ORDER BY h.starts_at, h.id", $params);
  $nowText = hearing_fmt($now);
  return array_map(static function (array $row) use ($nowText): array {
    return [
      'id' => (int)$row['id'],
      'complaint_id' => (int)$row['complaint_id'],
      'case_no' => $row['case_no'] === null ? null : (string)$row['case_no'],
      'reference_no' => (string)$row['reference_no'],
      'type_name' => hearing_type_name($row),
      'session_no' => (int)$row['session_no'],
      'starts_at' => (string)$row['starts_at'],
      'ends_at' => (string)$row['ends_at'],
      'venue' => (string)$row['venue'],
      'presiding_official_id' => (int)$row['presiding_official_id'],
      'presiding_name' => trim((string)$row['p_fname'] . ' ' . (string)$row['p_lname']),
      'status' => (string)$row['status'],
      'outcome' => $row['outcome'] === null ? null : (string)$row['outcome'],
      'party_count' => (int)$row['party_count'],
      // Fixed-width strings compare in time order.
      'needs_recording' => $row['status'] === 'scheduled' && (string)$row['ends_at'] < $nowText,
      'version' => (int)$row['version'],
    ];
  }, $rows);
}

/** GET list.php: ?from&to (at most 62 days apart) [&status][&official_id]. */
function hearing_list(mysqli $db, array $query): array {
  $from = hearing_parse_date($query['from'] ?? null);
  $to = hearing_parse_date($query['to'] ?? null);
  if ($from === null || $to === null) throw new ComplaintProblem('Enter valid from and to dates.', 422);
  if ($to < $from) throw new ComplaintProblem('The to date must not be before the from date.', 422);
  if ((int)$from->diff($to)->days > HEARING_MAX_RANGE_DAYS) throw new ComplaintProblem('Choose a range of at most 62 days.', 422);
  $where = 'h.starts_at >= ? AND h.starts_at < ?';
  $params = [hearing_fmt($from), hearing_fmt($to->modify('+1 day'))];
  $status = $query['status'] ?? null;
  if ($status !== null && $status !== '' && $status !== 'all') {
    if (!is_string($status) || !in_array($status, HEARING_STATUSES, true)) throw new ComplaintProblem('Invalid status.', 422);
    $where .= ' AND h.status = ?';
    $params[] = $status;
  }
  $official = $query['official_id'] ?? null;
  if ($official !== null && $official !== '' && $official !== 'all') {
    $officialId = hearing_query_id($official);
    if ($officialId === null) throw new ComplaintProblem('Invalid official.', 422);
    $where .= ' AND h.presiding_official_id = ?';
    $params[] = $officialId;
  }
  $now = hearing_now();
  return [
    'from' => $from->format('Y-m-d'),
    'to' => $to->format('Y-m-d'),
    'now' => hearing_fmt($now),
    'hearings' => hearing_calendar_rows($db, $where, $params, $now),
  ];
}

/** GET needs_recording.php: every scheduled session that has ended. */
function hearing_needs_recording(mysqli $db): array {
  $now = hearing_now();
  return [
    'now' => hearing_fmt($now),
    'hearings' => hearing_calendar_rows($db, "h.status = 'scheduled' AND h.ends_at < ?", [hearing_fmt($now)], $now),
  ];
}

/** Why scheduling is not possible now, or null. Same rules as the writes. */
function hearing_schedule_blocker(mysqli $db, array $complaint, array $parties): ?string {
  $problems = hearing_complaint_problems($db, $complaint, $parties, null);
  return $problems === [] ? null : $problems[0]['message'];
}

/** GET for_complaint.php: official view with attendance, notes and reasons. */
function hearing_for_complaint(mysqli $db, int $complaintId): array {
  $complaint = hearing_complaint_row($db, $complaintId, false);
  $parties = hearing_active_parties($db, $complaintId);
  $blocker = hearing_schedule_blocker($db, $complaint, $parties);
  $rows = complaint_party_rows($db,
    'SELECT h.*, o.fname AS p_fname, o.lname AS p_lname FROM complaint_hearings h
       JOIN barangay_officials o ON o.id = h.presiding_official_id
      WHERE h.complaint_id = ? ORDER BY h.session_no', [$complaintId]);
  $attendance = complaint_party_rows($db,
    'SELECT a.hearing_id, a.party_id, a.status, p.role, p.full_name, p.resident_id, p.is_active
       FROM complaint_hearing_attendance a
       JOIN complaint_hearings h ON h.id = a.hearing_id
       JOIN complaint_parties p ON p.id = a.party_id
      WHERE h.complaint_id = ? ORDER BY a.hearing_id, p.role, p.sort_order, p.id', [$complaintId]);
  $byHearing = [];
  foreach ($attendance as $row) {
    $byHearing[(int)$row['hearing_id']][] = [
      'party_id' => (int)$row['party_id'],
      'role' => (string)$row['role'],
      'full_name' => (string)$row['full_name'],
      'is_registered' => $row['resident_id'] !== null,
      'party_active' => (int)$row['is_active'] === 1,
      'status' => (string)$row['status'],
    ];
  }
  $activeRows = complaint_party_rows($db,
    'SELECT id, role, full_name FROM complaint_parties WHERE complaint_id = ? AND is_active = 1 ORDER BY role, sort_order, id', [$complaintId]);
  $sessions = [];
  foreach ($rows as $row) {
    $view = hearing_official_view($row);
    $view['attendance'] = $byHearing[$view['id']] ?? [];
    $view['added_after'] = [];
    if ($view['status'] === 'scheduled') {
      $onSession = array_flip(array_column($view['attendance'], 'party_id'));
      foreach ($activeRows as $party) {
        if (!isset($onSession[(int)$party['id']])) {
          $view['added_after'][] = ['party_id' => (int)$party['id'], 'role' => (string)$party['role'], 'full_name' => (string)$party['full_name']];
        }
      }
    }
    $sessions[] = $view;
  }
  return [
    'complaint' => [
      'id' => (int)$complaint['id'],
      'reference_no' => (string)$complaint['reference_no'],
      'case_no' => $complaint['case_no'] === null ? null : (string)$complaint['case_no'],
      'status' => (string)$complaint['status'],
      'type_name' => hearing_type_name($complaint),
      'can_schedule' => $blocker === null,
      'schedule_blocker' => $blocker,
    ],
    'now' => hearing_fmt(hearing_now()),
    'sessions' => $sessions,
  ];
}

/**
 * Filer view for api/complaints/list.php. Never private notes, reasons,
 * attendance or the presiding official. The summary only once completed.
 * @return array<int, list<array>> keyed by complaint id
 */
function hearing_resident_sessions(mysqli $db, array $complaintIds): array {
  $ids = array_values(array_unique(array_filter(array_map('intval', $complaintIds), static fn(int $id): bool => $id > 0)));
  $out = [];
  foreach (array_chunk($ids, 500) as $chunk) {
    $marks = implode(',', array_fill(0, count($chunk), '?'));
    foreach (complaint_party_rows($db,
      "SELECT complaint_id, session_no, starts_at, ends_at, venue, status, outcome, public_summary
         FROM complaint_hearings WHERE complaint_id IN ({$marks}) ORDER BY complaint_id, session_no", $chunk) as $row) {
      $completed = $row['status'] === 'completed';
      $out[(int)$row['complaint_id']][] = [
        'session_no' => (int)$row['session_no'],
        'starts_at' => (string)$row['starts_at'],
        'ends_at' => (string)$row['ends_at'],
        'venue' => (string)$row['venue'],
        'status' => (string)$row['status'],
        'outcome' => $completed && $row['outcome'] !== null ? (string)$row['outcome'] : null,
        'public_summary' => $completed && $row['public_summary'] !== null ? (string)$row['public_summary'] : null,
      ];
    }
  }
  return $out;
}

/** Scheduled sessions of a locked complaint (update_status, notify_resolved). */
function hearing_scheduled_count(mysqli $db, int $complaintId): int {
  $rows = complaint_party_rows($db,
    "SELECT COUNT(*) AS n FROM complaint_hearings WHERE complaint_id = ? AND status = 'scheduled' FOR SHARE", [$complaintId]);
  return (int)($rows[0]['n'] ?? 0);
}

/** GET officials.php: active officials for the presiding select. */
function hearing_officials(mysqli $db): array {
  return array_map(static fn(array $row): array => [
    'id' => (int)$row['id'],
    'name' => trim((string)$row['fname'] . ' ' . (string)$row['lname']),
    'position' => (string)$row['position'],
  ], complaint_party_rows($db,
    "SELECT id, fname, lname, position FROM barangay_officials WHERE status = 'active' ORDER BY fname, lname, id"));
}

// ---------------------------------------------------------------------------
// GET check.php
// ---------------------------------------------------------------------------

function hearing_check(mysqli $db, array $query): array {
  $complaintId = hearing_query_id($query['complaint_id'] ?? null);
  if ($complaintId === null) throw new ComplaintProblem('Complaint not found.', 404);
  $complaint = hearing_complaint_row($db, $complaintId, false);
  $excludeId = null;
  if (array_key_exists('exclude_hearing_id', $query) && $query['exclude_hearing_id'] !== '') {
    $excludeId = hearing_query_id($query['exclude_hearing_id']);
    $owner = $excludeId === null ? [] : complaint_party_rows($db,
      'SELECT id FROM complaint_hearings WHERE id = ? AND complaint_id = ?', [$excludeId, $complaintId]);
    if (!$owner) throw new ComplaintProblem('Invalid exclude_hearing_id.', 422);
  }
  $duration = $query['duration_minutes'] ?? null;
  $presiding = $query['presiding_official_id'] ?? null;
  $fields = [
    'date' => $query['date'] ?? null,
    'start_time' => $query['start_time'] ?? null,
    'duration_minutes' => is_string($duration) && preg_match('/^[1-9][0-9]{0,2}$/D', $duration) ? (int)$duration : null,
    'venue' => $query['venue'] ?? null,
    'presiding_official_id' => hearing_query_id($presiding) ?? 0,
  ];
  $evaluation = hearing_evaluate($db, $complaint, hearing_active_parties($db, $complaintId), $fields, $excludeId, hearing_now());
  return [
    'ok' => $evaluation['problems'] === [] && $evaluation['conflicts'] === [],
    'problems' => array_map(static fn(array $p): array => ['field' => $p['field'], 'message' => $p['message']], $evaluation['problems']),
    'conflicts' => $evaluation['conflicts'],
  ];
}

// ---------------------------------------------------------------------------
// Writes
// ---------------------------------------------------------------------------

/** One transaction. A 1062 on request_key (a race past the guard) becomes 409. */
function hearing_transaction(mysqli $db, callable $work): array {
  if (!$db->begin_transaction()) throw new RuntimeException($db->error, $db->errno);
  try {
    $result = $work();
    if (!$db->commit()) throw new RuntimeException($db->error, $db->errno);
    return $result;
  } catch (Throwable $error) {
    $db->rollback();
    if (!$error instanceof ComplaintProblem && (int)$error->getCode() === 1062) {
      throw new ComplaintProblem(HEARING_MSG_KEY_USED, 409);
    }
    throw $error;
  }
}

function hearing_lock_guard(mysqli $db): void {
  $rows = complaint_party_rows($db, 'SELECT id FROM complaint_hearing_guard WHERE id = 1 FOR UPDATE');
  if (!$rows) throw new RuntimeException('complaint_hearing_guard row 1 is missing.');
}

/** Locks one hearing of a locked complaint and checks its version. */
function hearing_lock(mysqli $db, int $hearingId, int $complaintId, int $version): array {
  $rows = complaint_party_rows($db,
    'SELECT * FROM complaint_hearings WHERE id = ? AND complaint_id = ? FOR UPDATE', [$hearingId, $complaintId]);
  if (!$rows) throw new ComplaintProblem('Session not found.', 404);
  if ((int)$rows[0]['version'] !== $version) throw new ComplaintProblem(HEARING_MSG_STALE, 409);
  return $rows[0];
}

/**
 * The complaint of a hearing, read before the complaint lock (the complaint is
 * always locked first). A locking read, so the read view opens only after the locks.
 */
function hearing_complaint_of(mysqli $db, int $hearingId): int {
  $rows = complaint_party_rows($db, 'SELECT complaint_id FROM complaint_hearings WHERE id = ? FOR SHARE', [$hearingId]);
  if (!$rows) throw new ComplaintProblem('Session not found.', 404);
  return (int)$rows[0]['complaint_id'];
}

/**
 * An earlier request with this key: the hearing it created, or null.
 * $fromId: null for schedule, the old hearing id for reschedule.
 */
function hearing_by_request_key(mysqli $db, string $key, int $complaintId, ?int $fromId): ?int {
  $rows = complaint_party_rows($db,
    'SELECT id, complaint_id, rescheduled_from_id FROM complaint_hearings WHERE request_key = ? FOR SHARE', [$key]);
  if (!$rows) return null;
  $row = $rows[0];
  $sameFrom = $fromId === null ? $row['rescheduled_from_id'] === null : (int)$row['rescheduled_from_id'] === $fromId;
  if ((int)$row['complaint_id'] !== $complaintId || !$sameFrom) throw new ComplaintProblem(HEARING_MSG_KEY_USED, 409);
  return (int)$row['id'];
}

/** BC-YYYY-NNNN on the first scheduling; never reassigned. YYYY = Manila year of scheduling. */
function hearing_assign_case_no(mysqli $db, array $complaint, DateTimeImmutable $now): string {
  if ($complaint['case_no'] !== null && (string)$complaint['case_no'] !== '') return (string)$complaint['case_no'];
  $year = (int)$now->format('Y');
  complaint_party_exec($db,
    'INSERT INTO complaint_case_counters (year, last_no) VALUES (?, LAST_INSERT_ID(1))
     ON DUPLICATE KEY UPDATE last_no = LAST_INSERT_ID(last_no + 1)', [$year]);
  $number = (int)complaint_party_rows($db, 'SELECT LAST_INSERT_ID() AS n')[0]['n'];
  if ($number <= 0) throw new RuntimeException('Case counter failed.');
  $caseNo = sprintf('BC-%04d-%04d', $year, $number);
  if (complaint_party_exec($db, 'UPDATE complaints SET case_no = ? WHERE id = ? AND case_no IS NULL', [$caseNo, (int)$complaint['id']]) !== 1) {
    throw new RuntimeException('Case number update failed.');
  }
  return $caseNo;
}

/**
 * The timeline row. open -> investigating when $toInvestigating; the note is
 * resident-safe (no names, reasons or notes).
 */
function hearing_timeline(mysqli $db, array $complaint, int $officialId, string $note, string $nowText, bool $toInvestigating): void {
  $from = (string)$complaint['status'];
  complaint_party_exec($db,
    'INSERT INTO complaint_updates (complaint_id, actor_type, actor_id, from_status, to_status, note, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?)',
    [(int)$complaint['id'], 'official', $officialId, $from, $toInvestigating ? 'investigating' : $from, $note, $nowText]);
}

/** open -> investigating, as update_status.php does it (confirmation rule and in-app notice). */
function hearing_mark_investigating(mysqli $db, array $complaint, string $nowText): void {
  $confirmation = $complaint['resident_confirmation'] === 'reopened' ? 'reopened' : null;
  complaint_party_exec($db,
    "UPDATE complaints SET status = 'investigating', resident_confirmation = ?, confirmed_at = NULL WHERE id = ?",
    [$confirmation, (int)$complaint['id']]);
  $residentId = (int)($complaint['resident_id'] ?? 0);
  if ($residentId > 0) {
    complaint_party_exec($db,
      'INSERT INTO notifications (resident_id, title, body, is_read, created_at) VALUES (?, ?, ?, ?, ?)',
      [$residentId, 'Complaint Update', 'Your complaint ' . $complaint['reference_no'] . ' is now investigating.', 0, $nowText]);
  }
}

/** Insert the session and one 'not_recorded' attendance row per active party. */
function hearing_insert(mysqli $db, array $complaint, array $parties, array $evaluation, int $officialId, string $requestKey, ?int $fromId, string $nowText): array {
  $sessionNo = (int)complaint_party_rows($db,
    'SELECT COALESCE(MAX(session_no), 0) + 1 AS n FROM complaint_hearings WHERE complaint_id = ?', [(int)$complaint['id']])[0]['n'];
  $stmt = complaint_party_stmt($db,
    'INSERT INTO complaint_hearings
       (complaint_id, session_no, starts_at, ends_at, venue, venue_key, presiding_official_id, status,
        rescheduled_from_id, request_key, version, created_by, updated_by, created_at, updated_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?)',
    [(int)$complaint['id'], $sessionNo, hearing_fmt($evaluation['start']), hearing_fmt($evaluation['end']),
     $evaluation['venue'], $evaluation['venue_key'], (int)$evaluation['presiding']['id'], 'scheduled',
     $fromId, $requestKey, $officialId, $officialId, $nowText, $nowText]);
  $hearingId = (int)$stmt->insert_id;
  $stmt->close();
  foreach ($parties as $party) {
    complaint_party_exec($db,
      "INSERT INTO complaint_hearing_attendance (hearing_id, party_id, status) VALUES (?, ?, 'not_recorded')",
      [$hearingId, (int)$party['id']]);
  }
  return ['id' => $hearingId, 'session_no' => $sessionNo];
}

/** Registered, active parties on a session (SMS recipients). */
function hearing_registered_residents(mysqli $db, int $hearingId): array {
  return array_values(array_unique(array_map('intval', array_column(complaint_party_rows($db,
    'SELECT p.resident_id FROM complaint_hearing_attendance a JOIN complaint_parties p ON p.id = a.party_id
      WHERE a.hearing_id = ? AND p.is_active = 1 AND p.resident_id IS NOT NULL ORDER BY p.resident_id', [$hearingId]), 'resident_id'))));
}

/**
 * SMS text, at most 160 characters, no names. Only the venue is shortened.
 * $event: scheduled | rescheduled | cancelled.
 */
function hearing_sms_body(string $event, string $caseNo, string $startsAt, string $venue): string {
  $when = hearing_when($startsAt);
  if ($event === 'cancelled') {
    return "Brgy. Sampaguita: Mediation for case {$caseNo} on {$when} was cancelled. The barangay will contact you.";
  }
  $head = $event === 'rescheduled'
    ? "Brgy. Sampaguita: Mediation for case {$caseNo} was moved to {$when} at "
    : "Brgy. Sampaguita: Mediation for case {$caseNo} is set on {$when} at ";
  $tail = '. Please come on time.';
  if (mb_strlen($head . $venue . $tail, 'UTF-8') <= HEARING_SMS_MAX) return $head . $venue . $tail;
  // Shortened venue: "...", then the sentence without its own full stop.
  $tail = ' Please come on time.';
  $room = HEARING_SMS_MAX - mb_strlen($head . $tail, 'UTF-8') - 3;
  return $head . rtrim(mb_substr($venue, 0, max(0, $room), 'UTF-8'), " .,") . '...' . $tail;
}

/** After the commit. A provider failure never undoes the write. */
function hearing_send_sms(array $residentIds, string $event, string $body): array {
  $results = [];
  foreach ($residentIds as $residentId) {
    try {
      $sent = notify_resident((int)$residentId, 'complaint_hearing_' . $event, $body, 'complaints_enabled');
    } catch (Throwable $error) {
      error_log('Hearing SMS failed: ' . $error->getMessage());
      $sent = ['status' => 'failed'];
    }
    $results[] = ['resident_id' => (int)$residentId, 'status' => (string)($sent['status'] ?? 'failed')];
  }
  return $results;
}

function hearing_audit(mysqli $db, int $officialId, string $officialName, string $action, string $reference, array $details): void {
  $json = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if (!log_audit($db, $officialId, $officialName, $action, 'complaint_hearing', mb_substr($reference, 0, 100, 'UTF-8'), $json === false ? '' : $json)) {
    error_log('Hearing audit failed: ' . $action);
  }
}

/** Strict body for schedule / reschedule. */
function hearing_proposal_fields(array $input): array {
  return [
    'date' => $input['date'] ?? null,
    'start_time' => $input['start_time'] ?? null,
    'duration_minutes' => $input['duration_minutes'] ?? null,
    'venue' => $input['venue'] ?? null,
    'presiding_official_id' => $input['presiding_official_id'] ?? null,
  ];
}

/**
 * POST schedule.php
 * {complaint_id, date, start_time, duration_minutes, venue, presiding_official_id, request_key}
 */
function hearing_schedule(mysqli $db, array $input, int $officialId, string $officialName): array {
  complaint_party_keys($input, ['complaint_id', 'date', 'start_time', 'duration_minutes', 'venue', 'presiding_official_id', 'request_key']);
  $complaintId = hearing_int($input, 'complaint_id');
  $requestKey = hearing_request_key($input['request_key'] ?? null);
  $fields = hearing_proposal_fields($input);

  $done = hearing_transaction($db, static function () use ($db, $complaintId, $requestKey, $fields, $officialId): array {
    hearing_lock_guard($db);
    $existing = hearing_by_request_key($db, $requestKey, $complaintId, null);
    if ($existing !== null) return ['idempotent' => true, 'hearing_id' => $existing];
    $complaint = hearing_complaint_row($db, $complaintId, true);
    $parties = hearing_active_parties($db, $complaintId);
    $now = hearing_now();
    $nowText = hearing_fmt($now);
    $evaluation = hearing_evaluate($db, $complaint, $parties, $fields, null, $now);
    hearing_enforce($evaluation);
    $caseNo = hearing_assign_case_no($db, $complaint, $now);
    $created = hearing_insert($db, $complaint, $parties, $evaluation, $officialId, $requestKey, null, $nowText);
    $toInvestigating = $complaint['status'] === 'open';
    hearing_timeline($db, $complaint, $officialId,
      'Mediation session ' . $created['session_no'] . ' scheduled for ' . hearing_when(hearing_fmt($evaluation['start']))
        . ' at ' . $evaluation['venue'] . '.', $nowText, $toInvestigating);
    if ($toInvestigating) hearing_mark_investigating($db, $complaint, $nowText);
    return [
      'idempotent' => false, 'hearing_id' => $created['id'], 'case_no' => $caseNo,
      'session_no' => $created['session_no'], 'starts_at' => hearing_fmt($evaluation['start']),
      'duration' => (int)$fields['duration_minutes'], 'presiding_official_id' => (int)$evaluation['presiding']['id'],
      'venue' => $evaluation['venue'],
    ];
  });
  return hearing_write_result($db, $done, 'scheduled', $officialId, $officialName);
}

/**
 * POST reschedule.php
 * {hearing_id, version, date, start_time, duration_minutes, venue, presiding_official_id, reason, request_key}
 */
function hearing_reschedule(mysqli $db, array $input, int $officialId, string $officialName): array {
  complaint_party_keys($input, ['hearing_id', 'version', 'date', 'start_time', 'duration_minutes', 'venue', 'presiding_official_id', 'reason', 'request_key']);
  $hearingId = hearing_int($input, 'hearing_id');
  $version = hearing_int($input, 'version');
  $requestKey = hearing_request_key($input['request_key'] ?? null);
  $reason = hearing_reason($input['reason'] ?? null);
  $fields = hearing_proposal_fields($input);

  $done = hearing_transaction($db, static function () use ($db, $hearingId, $version, $requestKey, $reason, $fields, $officialId): array {
    hearing_lock_guard($db);
    $complaintId = hearing_complaint_of($db, $hearingId);
    $existing = hearing_by_request_key($db, $requestKey, $complaintId, $hearingId);
    if ($existing !== null) return ['idempotent' => true, 'hearing_id' => $existing, 'previous_id' => $hearingId];
    $complaint = hearing_complaint_row($db, $complaintId, true);
    $old = hearing_lock($db, $hearingId, $complaintId, $version);
    if ($old['status'] !== 'scheduled') throw new ComplaintProblem(HEARING_MSG_NOT_SCHEDULED, 409);
    $parties = hearing_active_parties($db, $complaintId);
    $now = hearing_now();
    $nowText = hearing_fmt($now);
    $evaluation = hearing_evaluate($db, $complaint, $parties, $fields, $hearingId, $now);
    hearing_enforce($evaluation);
    complaint_party_exec($db,
      "UPDATE complaint_hearings SET status = 'rescheduled', change_reason = ?, version = version + 1, updated_by = ?, updated_at = ?
        WHERE id = ? AND version = ?", [$reason, $officialId, $nowText, $hearingId, $version]);
    $caseNo = hearing_assign_case_no($db, $complaint, $now);
    $created = hearing_insert($db, $complaint, $parties, $evaluation, $officialId, $requestKey, $hearingId, $nowText);
    $toInvestigating = $complaint['status'] === 'open';
    hearing_timeline($db, $complaint, $officialId,
      'Mediation session ' . (int)$old['session_no'] . ' rescheduled; new session ' . $created['session_no'] . ' on '
        . hearing_when(hearing_fmt($evaluation['start'])) . ' at ' . $evaluation['venue'] . '.', $nowText, $toInvestigating);
    if ($toInvestigating) hearing_mark_investigating($db, $complaint, $nowText);
    return [
      'idempotent' => false, 'hearing_id' => $created['id'], 'previous_id' => $hearingId, 'case_no' => $caseNo,
      'session_no' => $created['session_no'], 'starts_at' => hearing_fmt($evaluation['start']),
      'duration' => (int)$fields['duration_minutes'], 'presiding_official_id' => (int)$evaluation['presiding']['id'],
      'venue' => $evaluation['venue'], 'previous_session_no' => (int)$old['session_no'],
    ];
  });
  $result = hearing_write_result($db, $done, 'rescheduled', $officialId, $officialName);
  $previous = hearing_get($db, $hearingId);
  $result['previous'] = ['id' => $previous['id'], 'status' => $previous['status'], 'version' => $previous['version']];
  return $result;
}

/** Shared tail of schedule / reschedule: audit, SMS, response. */
function hearing_write_result(mysqli $db, array $done, string $event, int $officialId, string $officialName): array {
  $hearing = hearing_get($db, (int)$done['hearing_id']);
  $complaint = hearing_complaint_row($db, $hearing['complaint_id'], false);
  $caseNo = (string)$complaint['case_no'];
  $sms = [];
  if (!$done['idempotent']) {
    $details = ['hearing_id' => $hearing['id'], 'session_no' => $hearing['session_no'], 'starts_at' => $hearing['starts_at'],
      'duration_minutes' => $done['duration'], 'presiding_official_id' => $done['presiding_official_id']];
    if ($event === 'rescheduled') $details['previous_hearing_id'] = $done['previous_id'];
    hearing_audit($db, $officialId, $officialName, $event === 'rescheduled' ? 'Rescheduled mediation' : 'Scheduled mediation', $caseNo, $details);
    $sms = hearing_send_sms(hearing_registered_residents($db, $hearing['id']), $event,
      hearing_sms_body($event, $caseNo, $hearing['starts_at'], $hearing['venue']));
  }
  return [
    'idempotent' => (bool)$done['idempotent'],
    'case_no' => $caseNo,
    'complaint_status' => (string)$complaint['status'],
    'hearing' => $hearing,
    'sms' => $sms,
  ];
}

/** POST cancel.php {hearing_id, version, reason} */
function hearing_cancel(mysqli $db, array $input, int $officialId, string $officialName): array {
  complaint_party_keys($input, ['hearing_id', 'version', 'reason']);
  $hearingId = hearing_int($input, 'hearing_id');
  $version = hearing_int($input, 'version');
  $reason = hearing_reason($input['reason'] ?? null);
  $done = hearing_transaction($db, static function () use ($db, $hearingId, $version, $reason, $officialId): array {
    hearing_lock_guard($db);
    $complaintId = hearing_complaint_of($db, $hearingId);
    $complaint = hearing_complaint_row($db, $complaintId, true);
    $row = hearing_lock($db, $hearingId, $complaintId, $version);
    if ($row['status'] !== 'scheduled') throw new ComplaintProblem(HEARING_MSG_NOT_SCHEDULED, 409);
    $nowText = hearing_fmt(hearing_now());
    complaint_party_exec($db,
      "UPDATE complaint_hearings SET status = 'cancelled', change_reason = ?, version = version + 1, updated_by = ?, updated_at = ?
        WHERE id = ? AND version = ?", [$reason, $officialId, $nowText, $hearingId, $version]);
    hearing_timeline($db, $complaint, $officialId, 'Mediation session ' . (int)$row['session_no'] . ' cancelled.', $nowText, false);
    return ['case_no' => (string)$complaint['case_no'], 'session_no' => (int)$row['session_no'], 'starts_at' => (string)$row['starts_at']];
  });
  hearing_audit($db, $officialId, $officialName, 'Cancelled mediation', $done['case_no'],
    ['hearing_id' => $hearingId, 'session_no' => $done['session_no'], 'starts_at' => $done['starts_at']]);
  $hearing = hearing_get($db, $hearingId);
  $sms = hearing_send_sms(hearing_registered_residents($db, $hearingId), 'cancelled',
    hearing_sms_body('cancelled', $done['case_no'], $done['starts_at'], $hearing['venue']));
  return ['hearing' => $hearing, 'sms' => $sms];
}

/**
 * POST record.php
 * {hearing_id, version, attendance: {party_id: status}, outcome?, public_summary?, private_notes?, complete}
 * Omitted optional keys keep what is stored; null or "" clears.
 */
function hearing_record(mysqli $db, array $input, int $officialId, string $officialName): array {
  complaint_party_keys($input, ['hearing_id', 'version', 'attendance', 'outcome', 'public_summary', 'private_notes', 'complete']);
  $hearingId = hearing_int($input, 'hearing_id');
  $version = hearing_int($input, 'version');
  if (!array_key_exists('complete', $input) || !is_bool($input['complete'])) throw new ComplaintProblem('A valid complete flag is required.', 422);
  $complete = $input['complete'];
  $attendanceIn = $input['attendance'] ?? [];
  // {} and [] both decode to []; a JSON list has key 0, which is never a party id.
  if (!is_array($attendanceIn)) throw new ComplaintProblem('Attendance must be an object of party ids.', 422);
  $attendance = [];
  foreach ($attendanceIn as $partyKey => $status) {
    $partyId = is_int($partyKey) ? $partyKey : hearing_query_id((string)$partyKey);
    if ($partyId === null || $partyId <= 0) throw new ComplaintProblem('Attendance must be an object of party ids.', 422);
    if (!is_string($status) || !in_array($status, HEARING_ATTENDANCE, true)) throw new ComplaintProblem('Choose present, absent or excused.', 422);
    $attendance[$partyId] = $status;
  }
  $set = [];
  if (array_key_exists('outcome', $input)) {
    $outcome = $input['outcome'];
    if ($outcome === '' ) $outcome = null;
    if ($outcome !== null && (!is_string($outcome) || !in_array($outcome, HEARING_OUTCOMES, true))) throw new ComplaintProblem('Choose an outcome.', 422);
    $set['outcome'] = $outcome;
  }
  if (array_key_exists('public_summary', $input)) {
    $set['public_summary'] = hearing_text($input['public_summary'], 'Public summary', 0, 1000, 'Public summary must be 10 to 1000 characters.');
    if ($set['public_summary'] !== null && mb_strlen($set['public_summary'], 'UTF-8') < 10) {
      throw new ComplaintProblem('Public summary must be 10 to 1000 characters.', 422);
    }
  }
  if (array_key_exists('private_notes', $input)) {
    $set['private_notes'] = hearing_text($input['private_notes'], 'Private notes', 0, 5000, 'Private notes must be 5000 characters or fewer.');
  }

  $done = hearing_transaction($db, static function () use ($db, $hearingId, $version, $attendance, $set, $complete, $officialId): array {
    hearing_lock_guard($db);
    $complaintId = hearing_complaint_of($db, $hearingId);
    $complaint = hearing_complaint_row($db, $complaintId, true);
    $row = hearing_lock($db, $hearingId, $complaintId, $version);
    if ($row['status'] !== 'scheduled') throw new ComplaintProblem(HEARING_MSG_NOT_SCHEDULED, 409);
    $now = hearing_now();
    $nowText = hearing_fmt($now);
    if ((string)$row['starts_at'] > $nowText) throw new ComplaintProblem(HEARING_MSG_NOT_STARTED, 422);
    $stored = [];
    foreach (complaint_party_rows($db, 'SELECT party_id, status FROM complaint_hearing_attendance WHERE hearing_id = ? FOR UPDATE', [$hearingId]) as $a) {
      $stored[(int)$a['party_id']] = (string)$a['status'];
    }
    foreach (array_keys($attendance) as $partyId) {
      if (!array_key_exists($partyId, $stored)) throw new ComplaintProblem('Attendance includes someone who is not on this session.', 422);
    }
    $final = array_replace($stored, $attendance);
    $values = [
      'outcome' => array_key_exists('outcome', $set) ? $set['outcome'] : $row['outcome'],
      'public_summary' => array_key_exists('public_summary', $set) ? $set['public_summary'] : $row['public_summary'],
      'private_notes' => array_key_exists('private_notes', $set) ? $set['private_notes'] : $row['private_notes'],
    ];
    if ($complete) {
      if (in_array('not_recorded', $final, true)) throw new ComplaintProblem('Record attendance for everyone before completing.', 422);
      if ($values['outcome'] === null) throw new ComplaintProblem('Choose an outcome.', 422);
      if ($values['public_summary'] === null) throw new ComplaintProblem('Public summary must be 10 to 1000 characters.', 422);
    }
    $attendanceChanged = array_filter($attendance, static fn(string $s, int $p): bool => $stored[$p] !== $s, ARRAY_FILTER_USE_BOTH);
    $fieldsChanged = $values['outcome'] !== $row['outcome'] || $values['public_summary'] !== $row['public_summary']
      || $values['private_notes'] !== $row['private_notes'];
    if (!$complete && $attendanceChanged === [] && !$fieldsChanged) return ['changed' => false];
    foreach ($attendanceChanged as $partyId => $status) {
      complaint_party_exec($db, 'UPDATE complaint_hearing_attendance SET status = ? WHERE hearing_id = ? AND party_id = ?', [$status, $hearingId, $partyId]);
    }
    complaint_party_exec($db,
      'UPDATE complaint_hearings SET outcome = ?, public_summary = ?, private_notes = ?, status = ?, completed_at = ?,
              version = version + 1, updated_by = ?, updated_at = ? WHERE id = ? AND version = ?',
      [$values['outcome'], $values['public_summary'], $values['private_notes'], $complete ? 'completed' : 'scheduled',
       $complete ? $nowText : null, $officialId, $nowText, $hearingId, $version]);
    if ($complete) {
      hearing_timeline($db, $complaint, $officialId, 'Mediation session ' . (int)$row['session_no'] . ' completed. Outcome: '
        . HEARING_OUTCOME_LABELS[$values['outcome']] . '.', $nowText, false);
    }
    return ['changed' => true, 'case_no' => (string)$complaint['case_no'], 'session_no' => (int)$row['session_no'],
      'counts' => array_count_values($final), 'outcome' => $values['outcome']];
  });
  if ($done['changed']) {
    hearing_audit($db, $officialId, $officialName, $complete ? 'Completed mediation' : 'Recorded mediation', $done['case_no'],
      ['hearing_id' => $hearingId, 'session_no' => $done['session_no'], 'attendance' => $done['counts'], 'outcome' => $done['outcome']]);
  }
  return ['changed' => (bool)$done['changed'], 'hearing' => hearing_get($db, $hearingId)];
}

// ---------------------------------------------------------------------------
// Endpoint helpers
// ---------------------------------------------------------------------------

/** json_error with the problem's extra keys (conflicts). */
function hearing_fail(ComplaintProblem $problem): never {
  http_response_code($problem->status);
  echo json_encode(['success' => false, 'message' => $problem->getMessage()] + $problem->extra);
  exit;
}

/** GET guard shared by the read endpoints. */
function hearing_require_get(): void {
  if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_error('Method not allowed.', 405);
  session_write_close();
  header('Cache-Control: private, no-store');
}
