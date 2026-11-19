<?php
/**
 * BarangayLink - Summons for one respondent of one scheduled mediation session (complaint revision Phase 7).
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * Officials only. The internal 'Summons' document type (audience 'internal',
 * code 'summons') supplies the template: its active version, filled with
 * DOCUMENT_SUMMONS_FIELDS through the same DOCX fill + LibreOffice conversion as
 * document requests (api/documents/_docx_render.php).
 *
 * GENERATE. GET_LOCK('summons_<hearing>_<party>', 0) (busy -> 409); preconditions;
 * render in a temp folder outside any transaction; publish the two files under
 * generated_documents/complaints/<uuid>.pdf/.docx (exclusive create); then one
 * transaction: complaint FOR UPDATE -> hearing FOR SHARE (the same complaint ->
 * hearing order the hearing writers use; the hearing guard is not taken),
 * re-check every rule, mark the current row superseded, insert the new row,
 * add the resident-safe timeline note. Any failure removes the published files
 * and leaves no row. Audit after the commit (no names, no file names, no hashes).
 *
 * TIME. hearing_now() (Asia/Manila) is the only clock, as in _hearings.php.
 *
 * Rules throw ComplaintProblem; endpoints answer through hearing_fail().
 */

require_once __DIR__ . '/_hearings.php';
require_once __DIR__ . '/../documents/_templates.php';
require_once __DIR__ . '/../documents/_docx_render.php';

const SUMMONS_MSG_NOT_UPCOMING = 'Summons can only be issued for an upcoming scheduled session.';
const SUMMONS_MSG_ADDED_AFTER = 'This respondent was added after the session was scheduled. Reschedule to include them.';
const SUMMONS_MSG_NOT_RESPONDENT = 'Summons can only be issued to an active respondent of this complaint.';
const SUMMONS_MSG_BUSY = 'Summons for this respondent is being generated. Try again shortly.';
const SUMMONS_MSG_SIGNATORY = 'Document signatory is not configured.';
const SUMMONS_MSG_TEMPLATE = 'Summons template is unavailable.';
const SUMMONS_MSG_FAILED = 'Summons could not be generated. Try again.';
const SUMMONS_MSG_NOT_MIGRATED = 'Summons needs the 20261119 database migration.';
const SUMMONS_MSG_NO_CASE = 'This complaint has no case number yet.';
const SUMMONS_NOTE = 'Summons issued for session %d.';
const SUMMONS_HTACCESS = "# Direct HTTP access to generated summons is denied.\n"
    . "# Signed-in officials read them through api/hearings/summons_view.php.\n"
    . "# Apache 2.4. This file is not applied by PHP's built-in server.\n"
    . "Options -Indexes\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
    . "<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n";

// ---------------------------------------------------------------------------
// Pure value helpers (tested directly)
// ---------------------------------------------------------------------------

/** 1st 2nd 3rd 4th ... 11th 12th 13th ... 21st 22nd 23rd ... 31st */
function summons_ordinal(int $n): string {
  $mod100 = $n % 100;
  if ($mod100 >= 11 && $mod100 <= 13) return $n . 'th';
  return $n . match ($n % 10) { 1 => 'st', 2 => 'nd', 3 => 'rd', default => 'th' };
}

/** "A", "A and B", "A, B and C". */
function summons_join_names(array $names): string {
  $names = array_values(array_filter(array_map(static fn($n): string => trim((string)$n), $names), static fn(string $n): bool => $n !== ''));
  if (count($names) <= 1) return $names[0] ?? '';
  $last = array_pop($names);
  return implode(', ', $names) . ' and ' . $last;
}

/** '9:00 in the morning' (before 12:00) / '1:30 in the afternoon' (12:00 and later). */
function summons_time_words(DateTimeImmutable $at): string {
  return $at->format('g:i') . ((int)$at->format('G') < 12 ? ' in the morning' : ' in the afternoon');
}

/**
 * The 13 template values.
 * $hearing: starts_at, venue. $complaint: case_no, type_name.
 * $parties: active parties (role, full_name) in display order.
 * $respondent: full_name, address (already resolved: live residents.address
 * for a registered party, the stored address otherwise; null/'' when none).
 */
function summons_values(array $hearing, array $complaint, array $parties, array $respondent, string $captainName, DateTimeImmutable $now): array {
  $starts = hearing_from_db((string)$hearing['starts_at']);
  $issued = $now->setTimezone(hearing_tz());
  $byRole = ['complainant' => [], 'respondent' => []];
  foreach ($parties as $party) $byRole[(string)$party['role']][] = (string)$party['full_name'];
  $address = trim((string)($respondent['address'] ?? ''));
  return [
    'case_no' => (string)$complaint['case_no'],
    'case_title' => (string)$complaint['type_name'],
    'complainant_names' => summons_join_names($byRole['complainant']),
    'respondent_names' => summons_join_names($byRole['respondent']),
    'respondent_to' => trim((string)$respondent['full_name']) . ($address !== '' ? ', ' . $address : ''),
    'hearing_day' => summons_ordinal((int)$starts->format('j')),
    'hearing_month_year' => $starts->format('F Y'),
    'hearing_time' => summons_time_words($starts),
    'venue' => summons_venue((string)$hearing['venue']),
    'issued_day' => summons_ordinal((int)$issued->format('j')),
    'issued_month' => $issued->format('F'),
    'issued_year' => $issued->format('Y'),
    'official_name' => DOCUMENT_SIGNATORY_HONORIFIC . ' ' . ltrim($captainName),
  ];
}

/**
 * The active template says "at the {{venue}}" (v11): one leading separate word
 * "the" is dropped, case-insensitive, so "The Covered Court" does not print
 * "at the The Covered Court". "Theater Hall" and "Barangay Hall" are unchanged.
 */
function summons_venue(string $venue): string {
  $venue = trim($venue);
  $stripped = preg_replace('/^the\s+/iu', '', $venue, 1);
  return trim(is_string($stripped) && $stripped !== '' ? $stripped : $venue);
}

/** "Summons-BC-2026-0007-pedro-santos" (no extension). */
function summons_download_base(string $caseNo, string $name): string {
  $case = preg_replace('/[^A-Za-z0-9-]+/', '-', $caseNo) ?? '';
  $ascii = function_exists('iconv') ? (string)@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) : $name;
  $slug = strtolower(trim((string)preg_replace('/[^A-Za-z0-9]+/', '-', $ascii), '-'));
  return 'Summons-' . (trim($case, '-') ?: 'case') . '-' . ($slug !== '' ? mb_substr($slug, 0, 60) : 'respondent');
}

// ---------------------------------------------------------------------------
// Storage and configuration
// ---------------------------------------------------------------------------

/** complaint_documents present (20261119 migration)? */
function summons_ready(mysqli $db): bool {
  $rows = complaint_party_rows($db, "SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'complaint_documents'");
  return (int)$rows[0]['n'] === 1;
}

/** generated_documents/complaints, created with its deny rule on first use. */
function summons_storage_dir(bool $create): string {
  $dir = document_storage_dir('complaints');
  if ($create) {
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Cannot create the summons folder.');
    $rule = $dir . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($rule)) {
      $handle = @fopen($rule, 'xb');
      if ($handle !== false) { fwrite($handle, SUMMONS_HTACCESS); fclose($handle); }
    }
  }
  return $dir;
}

/** Path of a stored file, from the DB row's uuid only, contained in the folder; null when missing. */
function summons_file_path(string $uuid, string $extension): ?string {
  if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $uuid) || !in_array($extension, ['pdf', 'docx'], true)) return null;
  $root = realpath(summons_storage_dir(false));
  if ($root === false) return null;
  $path = realpath($root . DIRECTORY_SEPARATOR . $uuid . '.' . $extension);
  if ($path === false || !is_file($path) || !str_starts_with(strtolower($path), strtolower($root . DIRECTORY_SEPARATOR))) return null;
  return $path;
}

function summons_uuid(): string {
  $bytes = random_bytes(16);
  $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
  $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
  return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

/** Captain name, or null when not set. */
function summons_captain(mysqli $db): ?string {
  $captain = document_setting_get($db, 'captain_name');
  return $captain === null || trim($captain) === '' ? null : trim($captain);
}

/** The Summons type's active version: ['version' => row, 'path' => file]; null when unavailable. */
function summons_template(mysqli $db): ?array {
  if (!document_types_audience_ready($db) || !document_registry_active_version_table_exists($db)) return null;
  $rows = complaint_party_rows($db,
    "SELECT id FROM document_types WHERE code = ? AND audience = 'internal' AND status = 'active'", [DOCUMENT_SUMMONS_CODE]);
  if (!$rows) return null;
  $version = document_template_active_version($db, (int)$rows[0]['id']);
  if ($version === null) return null;
  try {
    $path = document_template_version_path((string)$version['file_name']);
  } catch (Throwable $e) {
    error_log('Summons template file missing: ' . $e->getMessage());
    return null;
  }
  return ['version' => $version, 'path' => $path];
}

function summons_urls(int $id): array {
  return [
    'view_url' => '../api/hearings/summons_view.php?id=' . $id . '&format=pdf',
    'download_url' => '../api/hearings/summons_view.php?id=' . $id . '&format=docx',
  ];
}

// ---------------------------------------------------------------------------
// Rules
// ---------------------------------------------------------------------------

/** Hearing row (no lock unless $lockShare). 404 when missing. */
function summons_hearing(mysqli $db, int $hearingId, bool $lockShare = false): array {
  $rows = complaint_party_rows($db,
    'SELECT id, complaint_id, session_no, starts_at, ends_at, venue, status FROM complaint_hearings WHERE id = ?' . ($lockShare ? ' FOR SHARE' : ''),
    [$hearingId]);
  if (!$rows) throw new ComplaintProblem('Session not found.', 404);
  return $rows[0];
}

function summons_upcoming(array $hearing, DateTimeImmutable $now): bool {
  // Fixed-width 'Y-m-d H:i:s' strings compare in time order.
  return (string)$hearing['status'] === 'scheduled' && (string)$hearing['starts_at'] > hearing_fmt($now);
}

/** Attendance party ids of a hearing. */
function summons_attendance_ids(mysqli $db, int $hearingId): array {
  return array_map('intval', array_column(complaint_party_rows($db,
    'SELECT party_id FROM complaint_hearing_attendance WHERE hearing_id = ?', [$hearingId]), 'party_id'));
}

/**
 * Everything the rules and the values need, read for one hearing + party.
 * Throws the first failing rule: 404 session / party, 409 not upcoming,
 * 422 not an active respondent of this complaint, 422 added after scheduling,
 * 409 no case number, 409 fee not paid or waived (Phase 10).
 */
function summons_context(mysqli $db, int $hearingId, int $partyId, DateTimeImmutable $now, bool $locked = false): array {
  $hearing = summons_hearing($db, $hearingId, $locked);
  $party = complaint_party_rows($db, 'SELECT id, complaint_id, role, is_active FROM complaint_parties WHERE id = ?', [$partyId]);
  if (!$party) throw new ComplaintProblem('Party not found.', 404);
  $party = $party[0];
  if (!summons_upcoming($hearing, $now)) throw new ComplaintProblem(SUMMONS_MSG_NOT_UPCOMING, 409);
  if ((int)$party['complaint_id'] !== (int)$hearing['complaint_id'] || (string)$party['role'] !== 'respondent' || (int)$party['is_active'] !== 1) {
    throw new ComplaintProblem(SUMMONS_MSG_NOT_RESPONDENT, 422);
  }
  if (!in_array($partyId, summons_attendance_ids($db, $hearingId), true)) throw new ComplaintProblem(SUMMONS_MSG_ADDED_AFTER, 422);
  $complaint = hearing_complaint_row($db, (int)$hearing['complaint_id'], false);
  if ($complaint['case_no'] === null || (string)$complaint['case_no'] === '') throw new ComplaintProblem(SUMMONS_MSG_NO_CASE, 409);
  // Phase 10 fee gate (FOR SHARE when locked: after the complaint lock).
  if (!complaint_billing_clear($db, (int)$hearing['complaint_id'], $locked)) throw new ComplaintProblem(BILLING_MSG_GATE, 409);
  $complaint['type_name'] = hearing_type_name($complaint);
  $parties = array_map('complaint_party_official_view', complaint_party_load($db, [(int)$hearing['complaint_id']], true));
  $respondent = null;
  foreach ($parties as $row) if ((int)$row['id'] === $partyId) $respondent = $row;
  if ($respondent === null) throw new ComplaintProblem(SUMMONS_MSG_NOT_RESPONDENT, 422);
  return ['hearing' => $hearing, 'complaint' => $complaint, 'parties' => $parties, 'respondent' => $respondent];
}

// ---------------------------------------------------------------------------
// Generate
// ---------------------------------------------------------------------------

/**
 * POST {hearing_id, party_id} -> {document: {...}, superseded_id}.
 * $convert: TEST-ONLY seam replacing document_convert_pdf (never set by endpoints).
 */
function summons_generate(mysqli $db, array $input, int $officialId, string $officialName, ?callable $convert = null): array {
  complaint_party_keys($input, ['hearing_id', 'party_id']);
  $hearingId = hearing_int($input, 'hearing_id');
  $partyId = hearing_int($input, 'party_id');
  if (!summons_ready($db)) throw new ComplaintProblem(SUMMONS_MSG_NOT_MIGRATED, 503);

  // Cheap refusals before taking the lock (re-checked under the row locks).
  summons_context($db, $hearingId, $partyId, hearing_now());

  $lockName = 'summons_' . $hearingId . '_' . $partyId;
  $lock = complaint_party_rows($db, 'SELECT GET_LOCK(?, 0) AS got', [$lockName]);
  if ((int)($lock[0]['got'] ?? 0) !== 1) throw new ComplaintProblem(SUMMONS_MSG_BUSY, 409);

  $workspace = null; $published = []; $committed = false;
  try {
    $now = hearing_now();
    $context = summons_context($db, $hearingId, $partyId, $now);
    $captain = summons_captain($db);
    if ($captain === null) throw new ComplaintProblem(SUMMONS_MSG_SIGNATORY, 503);
    $template = summons_template($db);
    if ($template === null) throw new ComplaintProblem(SUMMONS_MSG_TEMPLATE, 503);
    $values = summons_values($context['hearing'], $context['complaint'], $context['parties'], $context['respondent'], $captain, $now);

    // Render outside any transaction (LibreOffice can take seconds).
    $uuid = summons_uuid();
    try {
      $workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'barangay-summons-' . bin2hex(random_bytes(12));
      if (!mkdir($workspace, 0700)) throw new RuntimeException('Cannot create summons workspace.');
      $working = $workspace . DIRECTORY_SEPARATOR . $uuid . '.docx';
      document_fill_docx($template['path'], DOCUMENT_SUMMONS_FIELDS, $values, $working);
      $pdf = $convert !== null ? $convert($working, $workspace, $uuid) : document_convert_pdf($working, $workspace, $uuid);
      if (!request_valid_pdf($pdf)) throw new RuntimeException('Summons conversion did not produce a valid PDF.');
      $dir = summons_storage_dir(true);
      foreach (['pdf' => $pdf, 'docx' => $working] as $extension => $source) {
        $destination = $dir . DIRECTORY_SEPARATOR . $uuid . '.' . $extension;
        request_copy_exclusive($source, $destination);
        $published[] = $destination;
      }
      $pdfSha = hash_file('sha256', $dir . DIRECTORY_SEPARATOR . $uuid . '.pdf');
      $docxSha = hash_file('sha256', $dir . DIRECTORY_SEPARATOR . $uuid . '.docx');
      if (!is_string($pdfSha) || !is_string($docxSha)) throw new RuntimeException('Cannot hash the summons files.');
    } catch (ComplaintProblem $problem) {
      throw $problem;
    } catch (Throwable $error) {
      error_log('Summons generation failed: ' . $error->getMessage());
      throw new ComplaintProblem(SUMMONS_MSG_FAILED, 503);
    }

    $nowText = hearing_fmt($now);
    $versionId = (int)$template['version']['id'];
    if (!$db->begin_transaction()) throw new RuntimeException($db->error, $db->errno);
    try {
      $complaint = hearing_complaint_row($db, (int)$context['hearing']['complaint_id'], true);
      $locked = summons_context($db, $hearingId, $partyId, $now, true);
      if ((string)$locked['complaint']['case_no'] !== $values['case_no']) throw new ComplaintProblem(COMPLAINT_PARTY_MSG_STALE, 409);
      $previous = complaint_party_rows($db,
        "SELECT id FROM complaint_documents WHERE hearing_id = ? AND party_id = ? AND kind = 'summons' AND status = 'current' FOR UPDATE",
        [$hearingId, $partyId]);
      $supersededId = $previous ? (int)$previous[0]['id'] : null;
      if ($supersededId !== null) {
        complaint_party_exec($db, "UPDATE complaint_documents SET status = 'superseded' WHERE id = ?", [$supersededId]);
      }
      $stmt = complaint_party_stmt($db,
        "INSERT INTO complaint_documents
           (complaint_id, hearing_id, party_id, kind, template_version_id, file_uuid, pdf_sha256, docx_sha256, status, generated_by, generated_at)
         VALUES (?, ?, ?, 'summons', ?, ?, ?, ?, 'current', ?, ?)",
        [(int)$complaint['id'], $hearingId, $partyId, $versionId, $uuid, $pdfSha, $docxSha, $officialId, $nowText]);
      $documentId = (int)$stmt->insert_id;
      $stmt->close();
      hearing_timeline($db, $complaint, $officialId, sprintf(SUMMONS_NOTE, (int)$locked['hearing']['session_no']), $nowText, false);
      if (!$db->commit()) throw new RuntimeException($db->error, $db->errno);
      $committed = true;
    } catch (Throwable $error) {
      $db->rollback();
      throw $error;
    }

    $details = json_encode(['hearing_id' => $hearingId, 'party_id' => $partyId, 'version' => (int)$template['version']['version_no']]);
    if (!log_audit($db, $officialId, $officialName, 'Generated summons', 'complaint_document', mb_substr($values['case_no'], 0, 100, 'UTF-8'), $details === false ? '' : $details)) {
      error_log('Summons audit failed.');
    }
    return [
      'document' => ['id' => $documentId, 'hearing_id' => $hearingId, 'party_id' => $partyId, 'status' => 'current',
        'generated_at' => $nowText, 'template_version' => (int)$template['version']['version_no']] + summons_urls($documentId),
      'superseded_id' => $supersededId,
    ];
  } finally {
    if (!$committed) foreach ($published as $path) @unlink($path);
    if ($workspace !== null) remove_dir_tree($workspace);
    complaint_party_rows($db, 'SELECT RELEASE_LOCK(?) AS released', [$lockName]);
  }
}

// ---------------------------------------------------------------------------
// Read
// ---------------------------------------------------------------------------

/** GET summons_list.php?hearing_id=N */
function summons_list(mysqli $db, int $hearingId): array {
  if (!summons_ready($db)) throw new ComplaintProblem(SUMMONS_MSG_NOT_MIGRATED, 503);
  $now = hearing_now();
  $hearing = summons_hearing($db, $hearingId);
  $complaint = hearing_complaint_row($db, (int)$hearing['complaint_id'], false);
  $upcoming = summons_upcoming($hearing, $now);
  $blocker = null;
  if (!$upcoming) $blocker = SUMMONS_MSG_NOT_UPCOMING;
  elseif ($complaint['case_no'] === null || (string)$complaint['case_no'] === '') $blocker = SUMMONS_MSG_NO_CASE;
  elseif (!complaint_billing_clear($db, (int)$hearing['complaint_id'])) $blocker = BILLING_MSG_GATE;
  elseif (summons_captain($db) === null) $blocker = SUMMONS_MSG_SIGNATORY;
  elseif (summons_template($db) === null) $blocker = SUMMONS_MSG_TEMPLATE;

  $onSession = array_flip(summons_attendance_ids($db, $hearingId));
  $respondents = complaint_party_rows($db,
    "SELECT p.id, p.full_name, p.is_active FROM complaint_parties p
      WHERE p.complaint_id = ? AND p.role = 'respondent'
        AND (p.is_active = 1 OR EXISTS (SELECT 1 FROM complaint_hearing_attendance a WHERE a.hearing_id = ? AND a.party_id = p.id))
      ORDER BY p.sort_order, p.id", [(int)$hearing['complaint_id'], $hearingId]);
  $documents = complaint_party_rows($db,
    "SELECT d.id, d.party_id, d.status, d.generated_at, o.fname, o.lname
       FROM complaint_documents d JOIN barangay_officials o ON o.id = d.generated_by
      WHERE d.hearing_id = ? AND d.kind = 'summons' ORDER BY d.generated_at DESC, d.id DESC", [$hearingId]);
  $byParty = [];
  foreach ($documents as $row) $byParty[(int)$row['party_id']][] = $row;

  $out = [];
  foreach ($respondents as $party) {
    $partyId = (int)$party['id'];
    $active = (int)$party['is_active'] === 1;
    $listed = isset($onSession[$partyId]);
    $current = null; $history = [];
    foreach ($byParty[$partyId] ?? [] as $row) {
      $item = ['id' => (int)$row['id'], 'generated_at' => (string)$row['generated_at'],
        'generated_by_name' => trim((string)$row['fname'] . ' ' . (string)$row['lname'])] + summons_urls((int)$row['id']);
      if ($row['status'] === 'current' && $current === null) $current = $item; else $history[] = $item;
    }
    $reason = $blocker ?? (!$active ? SUMMONS_MSG_NOT_RESPONDENT : (!$listed ? SUMMONS_MSG_ADDED_AFTER : null));
    $out[] = ['party_id' => $partyId, 'full_name' => (string)$party['full_name'], 'on_session' => $listed, 'party_active' => $active,
      'current' => $current, 'superseded_count' => count($history), 'history' => $history,
      'can_generate' => $reason === null, 'reason' => $reason];
  }
  return [
    'hearing' => ['id' => (int)$hearing['id'], 'session_no' => (int)$hearing['session_no'], 'status' => (string)$hearing['status'],
      'starts_at' => (string)$hearing['starts_at'], 'venue' => (string)$hearing['venue'],
      'case_no' => $complaint['case_no'] === null ? null : (string)$complaint['case_no'], 'upcoming' => $upcoming],
    'can_issue' => $blocker === null,
    'blocker' => $blocker,
    'respondents' => $out,
  ];
}

/**
 * Per scheduled session of a complaint: respondents on the session (active)
 * and how many of them have a current summons. [] before the migration.
 * @return array<int, array{issued:int, total:int}> keyed by hearing id
 */
function summons_counts(mysqli $db, int $complaintId): array {
  if (!summons_ready($db)) return [];
  $rows = complaint_party_rows($db,
    "SELECT h.id AS hearing_id, COUNT(p.id) AS total,
            SUM(EXISTS (SELECT 1 FROM complaint_documents d WHERE d.hearing_id = h.id AND d.party_id = p.id
                          AND d.kind = 'summons' AND d.status = 'current')) AS issued
       FROM complaint_hearings h
       JOIN complaint_hearing_attendance a ON a.hearing_id = h.id
       JOIN complaint_parties p ON p.id = a.party_id AND p.role = 'respondent' AND p.is_active = 1
      WHERE h.complaint_id = ? AND h.status = 'scheduled'
      GROUP BY h.id", [$complaintId]);
  $out = [];
  foreach ($rows as $row) $out[(int)$row['hearing_id']] = ['issued' => (int)$row['issued'], 'total' => (int)$row['total']];
  return $out;
}

/** The stored row for summons_view.php, with the download name. 404 when unknown. */
function summons_document(mysqli $db, int $id): array {
  if (!summons_ready($db)) throw new ComplaintProblem('Summons is unavailable.', 404);
  $rows = complaint_party_rows($db,
    "SELECT d.id, d.file_uuid, c.case_no, p.full_name
       FROM complaint_documents d
       JOIN complaints c ON c.id = d.complaint_id
       JOIN complaint_parties p ON p.id = d.party_id
      WHERE d.id = ? AND d.kind = 'summons'", [$id]);
  if (!$rows) throw new ComplaintProblem('Summons is unavailable.', 404);
  $row = $rows[0];
  $row['download_base'] = summons_download_base((string)$row['case_no'], (string)$row['full_name']);
  return $row;
}
