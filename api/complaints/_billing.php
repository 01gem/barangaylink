<?php
/**
 * BarangayLink - Complaint billing: one fee per complaint (complaint revision Phase 10).
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * RULES. complaint_types.default_fee (0 = no fee) prefills the bill. Staff issue
 * one bill per complaint (open / investigating only), edit it while it is
 * unpaid, then mark it paid (optional receipt no.) or waive it (reason). Paid
 * and waived are final. Any official (staff or admin) may do each step.
 *
 * GATE. complaint_billing_clear(): true when the type has no fee and there is no
 * bill, or the bill is paid / waived. Scheduling, rescheduling and summons
 * generation refuse with BILLING_MSG_GATE (409) otherwise
 * (_hearings.php hearing_complaint_problems, _summons.php summons_context).
 * Before the 20261120 migration no fee can exist, so the gate is clear and the
 * bill endpoints answer 503 (D1).
 *
 * MONEY. Input is a JSON string /^\d{1,6}(\.\d{1,2})?$/D, turned into integer
 * cents (never float), stored as DECIMAL(10,2) text 'N.NN'.
 *
 * LOCKING. One transaction: the complaint FOR UPDATE -> the bill FOR UPDATE
 * (+ version). Hearing / summons writes lock the complaint first and then read
 * the bill FOR SHARE: the same order, no cycle.
 *
 * TIME. hearing_now() (Asia/Manila) is the only clock, as in _hearings.php.
 *
 * After the commit: audit (amount, receipt, reason: officials only), then the
 * in-app notice and SMS to registered complainants only. The timeline note is
 * resident-safe: 'Fee issued.' / 'Fee paid.' / 'Fee waived.', no amount or reason.
 */

require_once __DIR__ . '/_problem.php';
require_once __DIR__ . '/_parties.php';
require_once __DIR__ . '/../hearings/_hearings.php';

const BILLING_MAX_CENTS = 10000000;   // 100,000.00
const BILLING_STATUSES = ['unpaid', 'paid', 'waived'];
const BILLING_MSG_GATE = 'The complaint fee must be paid or waived first.';
const BILLING_MSG_AMOUNT = 'Enter an amount from 0.01 to 100,000.00.';
const BILLING_MSG_FEE = 'Enter a fee from 0 to 100,000.00.';
const BILLING_MSG_EXISTS = 'This complaint already has a fee.';
const BILLING_MSG_NONE = 'No fee has been issued for this complaint.';
const BILLING_MSG_FINAL = 'This fee is already settled and cannot be changed.';
const BILLING_MSG_ISSUE_STATUS = 'Fees can only be issued for open or investigating complaints.';
const BILLING_MSG_EDIT_STATUS = 'Fees can only be changed while the complaint is open or investigating.';
const BILLING_MSG_RECEIPT = 'Receipt no. may use letters, numbers, - and / (up to 50).';
const BILLING_MSG_NOT_MIGRATED = 'Billing needs the 20261120 database migration.';
const BILLING_NOTE = ['issued' => 'Fee issued.', 'paid' => 'Fee paid.', 'waived' => 'Fee waived.'];
const BILLING_NOTICE_TITLE = 'Complaint Fee';

// ---------------------------------------------------------------------------
// Pure helpers (tested directly)
// ---------------------------------------------------------------------------

/** 'N' / 'N.N' / 'N.NN' (1-6 digits) -> cents, or null when the text is not money. */
function billing_cents(mixed $raw): ?int {
  if (!is_string($raw) || !preg_match('/^(\d{1,6})(?:\.(\d{1,2}))?$/D', $raw, $m)) return null;
  return (int)$m[1] * 100 + (int)str_pad($m[2] ?? '', 2, '0');
}

/** cents -> 'N.NN' (the DECIMAL text). */
function billing_from_cents(int $cents): string {
  return intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
}

/** DECIMAL text from the database -> cents. */
function billing_db_cents(mixed $value): int {
  $cents = billing_cents((string)$value);
  if ($cents === null) throw new RuntimeException('Unexpected money value.');
  return $cents;
}

/** cents -> 'PHP 1,234.50' for people (integer formatting only). */
function billing_display(int $cents): string {
  return 'PHP ' . number_format(intdiv($cents, 100)) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
}

/** A bill amount: 0.01 .. 100,000.00. Returns 'N.NN'. */
function billing_amount(mixed $raw): string {
  $cents = billing_cents($raw);
  if ($cents === null || $cents < 1 || $cents > BILLING_MAX_CENTS) throw new ComplaintProblem(BILLING_MSG_AMOUNT, 422);
  return billing_from_cents($cents);
}

/** A type default fee: 0 .. 100,000.00. Returns 'N.NN'. */
function billing_fee(mixed $raw): string {
  $cents = billing_cents($raw);
  if ($cents === null || $cents > BILLING_MAX_CENTS) throw new ComplaintProblem(BILLING_MSG_FEE, 422);
  return billing_from_cents($cents);
}

function billing_note_value(mixed $raw): ?string {
  if ($raw === null) return null;
  $note = complaint_party_text($raw, 'Billing note', 0, 255);
  return $note === '' ? null : $note;
}

function billing_receipt(mixed $raw): ?string {
  if ($raw === null) return null;
  if (!is_string($raw)) throw new ComplaintProblem(BILLING_MSG_RECEIPT, 422);
  $receipt = trim($raw);
  if ($receipt === '') return null;
  if (!preg_match('/^[A-Za-z0-9\-\/ ]{1,50}$/D', $receipt)) throw new ComplaintProblem(BILLING_MSG_RECEIPT, 422);
  return $receipt;
}

/**
 * SMS text (GSM-7: "PHP", not the peso sign), at most 160 characters, no names.
 * $event: issued | paid | waived.
 */
function billing_sms_body(string $event, string $referenceNo, int $cents): string {
  return match ($event) {
    'issued' => "Brgy. Sampaguita: A fee of " . billing_display($cents) . " was issued for complaint {$referenceNo}. Please pay at the barangay hall.",
    'paid' => "Brgy. Sampaguita: Payment for complaint {$referenceNo} was received. Thank you.",
    'waived' => "Brgy. Sampaguita: The fee for complaint {$referenceNo} was waived. No payment is needed.",
  };
}

/** In-app notice body (notifications.body is VARCHAR(255)). */
function billing_notice_body(string $event, string $referenceNo, int $cents): string {
  return match ($event) {
    'issued' => 'A fee of ' . billing_display($cents) . " was issued for your complaint {$referenceNo}. Please pay at the barangay hall.",
    'paid' => "Payment for your complaint {$referenceNo} was received.",
    'waived' => "The fee for your complaint {$referenceNo} was waived.",
  };
}

// ---------------------------------------------------------------------------
// Readiness and the gate
// ---------------------------------------------------------------------------

/** complaint_bills and complaint_types.default_fee exist. Cached per connection thread. */
function complaint_billing_ready(mysqli $db): bool {
  static $cache = [];
  $key = (string)$db->thread_id;
  if (!array_key_exists($key, $cache)) {
    $rows = complaint_party_rows($db,
      "SELECT (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'complaint_bills')
            + (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'complaint_types' AND column_name = 'default_fee') AS n");
    $cache[$key] = (int)$rows[0]['n'] === 2;
  }
  return $cache[$key];
}

function billing_require_ready(mysqli $db): void {
  if (!complaint_billing_ready($db)) throw new ComplaintProblem(BILLING_MSG_NOT_MIGRATED, 503);
}

/**
 * The type fee and the bill of one complaint.
 * $lockShare: read the bill FOR SHARE (inside a write, after the complaint lock).
 * @return array{fee_cents:int, bill:?array, clear:bool}
 */
function complaint_billing_state(mysqli $db, int $complaintId, bool $lockShare = false): array {
  $fee = complaint_party_rows($db,
    'SELECT COALESCE(t.default_fee, 0.00) AS fee FROM complaints c LEFT JOIN complaint_types t ON t.id = c.complaint_type_id WHERE c.id = ?',
    [$complaintId]);
  if (!$fee) throw new ComplaintProblem('Complaint not found.', 404);
  $feeCents = billing_db_cents($fee[0]['fee']);
  $bills = complaint_party_rows($db,
    'SELECT * FROM complaint_bills WHERE complaint_id = ?' . ($lockShare ? ' FOR SHARE' : ''), [$complaintId]);
  $bill = $bills ? $bills[0] : null;
  $clear = $bill === null ? $feeCents === 0 : in_array((string)$bill['status'], ['paid', 'waived'], true);
  return ['fee_cents' => $feeCents, 'bill' => $bill, 'clear' => $clear];
}

/** The gate. Before the migration no fee can exist: clear. */
function complaint_billing_clear(mysqli $db, int $complaintId, bool $lockShare = false): bool {
  if (!complaint_billing_ready($db)) return true;
  return complaint_billing_state($db, $complaintId, $lockShare)['clear'];
}

// ---------------------------------------------------------------------------
// Views
// ---------------------------------------------------------------------------

function billing_name(mixed $fname, mixed $lname): ?string {
  $name = trim((string)$fname . ' ' . (string)$lname);
  return $name === '' ? null : $name;
}

/** Officials: everything, with names. */
function billing_official_view(array $row): array {
  return [
    'id' => (int)$row['id'],
    'amount' => billing_from_cents(billing_db_cents($row['amount'])),
    'billing_note' => $row['billing_note'] === null ? null : (string)$row['billing_note'],
    'status' => (string)$row['status'],
    'receipt_no' => $row['receipt_no'] === null ? null : (string)$row['receipt_no'],
    'waive_reason' => $row['waive_reason'] === null ? null : (string)$row['waive_reason'],
    'issued_at' => (string)$row['issued_at'],
    'issued_by_name' => billing_name($row['i_fname'] ?? null, $row['i_lname'] ?? null),
    'settled_at' => $row['settled_at'] === null ? null : (string)$row['settled_at'],
    'settled_by_name' => $row['settled_by'] === null ? null : billing_name($row['s_fname'] ?? null, $row['s_lname'] ?? null),
    'version' => (int)$row['version'],
  ];
}

/** api/complaints/list.php, officials: no ids, no names. */
function billing_list_official(array $row): array {
  return [
    'amount' => billing_from_cents(billing_db_cents($row['amount'])),
    'status' => (string)$row['status'],
    'billing_note' => $row['billing_note'] === null ? null : (string)$row['billing_note'],
    'receipt_no' => $row['receipt_no'] === null ? null : (string)$row['receipt_no'],
    'issued_at' => (string)$row['issued_at'],
    'settled_at' => $row['settled_at'] === null ? null : (string)$row['settled_at'],
    'waive_reason' => $row['waive_reason'] === null ? null : (string)$row['waive_reason'],
  ];
}

/** api/complaints/list.php, the filer: no receipt, no reason, no official. */
function billing_list_resident(array $row): array {
  return [
    'amount' => billing_from_cents(billing_db_cents($row['amount'])),
    'status' => (string)$row['status'],
    'billing_note' => $row['billing_note'] === null ? null : (string)$row['billing_note'],
    'issued_at' => (string)$row['issued_at'],
    'settled_at' => $row['settled_at'] === null ? null : (string)$row['settled_at'],
  ];
}

/**
 * For list.php: complaint id -> ['bill' => view|null, 'type_default_fee' => 'N.NN'].
 * $view: 'official' | 'resident'. [] before the migration.
 */
function complaint_billing_for_complaints(mysqli $db, array $complaintIds, string $view): array {
  $ids = array_values(array_unique(array_map('intval', $complaintIds)));
  if ($ids === [] || !complaint_billing_ready($db)) return [];
  $out = [];
  foreach (array_chunk($ids, 500) as $chunk) {
    $marks = implode(',', array_fill(0, count($chunk), '?'));
    $rows = complaint_party_rows($db,
      "SELECT c.id AS cid, COALESCE(t.default_fee, 0.00) AS fee, b.*
         FROM complaints c
         LEFT JOIN complaint_types t ON t.id = c.complaint_type_id
         LEFT JOIN complaint_bills b ON b.complaint_id = c.id
        WHERE c.id IN ({$marks})", $chunk);
    foreach ($rows as $row) {
      $bill = $row['id'] === null ? null : ($view === 'official' ? billing_list_official($row) : billing_list_resident($row));
      $out[(int)$row['cid']] = ['bill' => $bill, 'type_default_fee' => billing_from_cents(billing_db_cents($row['fee']))];
    }
  }
  return $out;
}

function billing_bill_row(mysqli $db, int $complaintId): ?array {
  $rows = complaint_party_rows($db,
    'SELECT b.*, i.fname AS i_fname, i.lname AS i_lname, s.fname AS s_fname, s.lname AS s_lname
       FROM complaint_bills b
       JOIN barangay_officials i ON i.id = b.issued_by
       LEFT JOIN barangay_officials s ON s.id = b.settled_by
      WHERE b.complaint_id = ?', [$complaintId]);
  return $rows ? $rows[0] : null;
}

/** GET bill_get.php */
function complaint_bill_get(mysqli $db, int $complaintId): array {
  billing_require_ready($db);
  $complaint = hearing_complaint_row($db, $complaintId, false);
  $state = complaint_billing_state($db, $complaintId);
  $row = billing_bill_row($db, $complaintId);
  return [
    'complaint' => [
      'id' => (int)$complaint['id'],
      'reference_no' => (string)$complaint['reference_no'],
      'status' => (string)$complaint['status'],
      'type_name' => hearing_type_name($complaint),
    ],
    'default_fee' => billing_from_cents($state['fee_cents']),
    'clear' => $state['clear'],
    'blocker' => $state['clear'] ? null : BILLING_MSG_GATE,
    'can_issue' => $row === null && in_array((string)$complaint['status'], ['open', 'investigating'], true),
    'bill' => $row === null ? null : billing_official_view($row),
  ];
}

// ---------------------------------------------------------------------------
// Writes
// ---------------------------------------------------------------------------

/** One transaction. A 1062 (a second bill raced past the lock) becomes 409. */
function billing_transaction(mysqli $db, callable $work): array {
  if (!$db->begin_transaction()) throw new RuntimeException($db->error, $db->errno);
  try {
    $result = $work();
    if (!$db->commit()) throw new RuntimeException($db->error, $db->errno);
    return $result;
  } catch (Throwable $error) {
    $db->rollback();
    if (!$error instanceof ComplaintProblem && (int)$error->getCode() === 1062) throw new ComplaintProblem(BILLING_MSG_EXISTS, 409);
    throw $error;
  }
}

/** The complaint FOR UPDATE, then its bill FOR UPDATE (404 when $need and missing). */
function billing_lock(mysqli $db, int $complaintId, bool $need): array {
  $complaint = hearing_complaint_row($db, $complaintId, true);
  $rows = complaint_party_rows($db, 'SELECT * FROM complaint_bills WHERE complaint_id = ? FOR UPDATE', [$complaintId]);
  if ($need && !$rows) throw new ComplaintProblem(BILLING_MSG_NONE, 404);
  return [$complaint, $rows ? $rows[0] : null];
}

/** Bill of a locked complaint, still unpaid, at the expected version. */
function billing_check_open(array $bill, int $version): void {
  if ((string)$bill['status'] !== 'unpaid') throw new ComplaintProblem(BILLING_MSG_FINAL, 409);
  if ((int)$bill['version'] !== $version) throw new ComplaintProblem(COMPLAINT_PARTY_MSG_STALE, 409);
}

function billing_editable_status(array $complaint): bool {
  return in_array((string)$complaint['status'], ['open', 'investigating'], true);
}

/** Active registered complainants (in-app + SMS recipients). */
function billing_recipients(mysqli $db, int $complaintId): array {
  return array_values(array_unique(array_map('intval', array_column(complaint_party_rows($db,
    "SELECT DISTINCT resident_id FROM complaint_parties
      WHERE complaint_id = ? AND role = 'complainant' AND is_active = 1 AND resident_id IS NOT NULL ORDER BY resident_id",
    [$complaintId]), 'resident_id'))));
}

/**
 * After the commit: audit, then in-app notice + SMS per registered complainant.
 * Never undoes the write; failures are logged.
 */
function billing_after(mysqli $db, string $event, string $action, array $complaint, int $cents, int $officialId, string $officialName, array $details, bool $notify): array {
  $json = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if (!log_audit($db, $officialId, $officialName, $action, 'complaint_bill', mb_substr((string)$complaint['reference_no'], 0, 100, 'UTF-8'), $json === false ? '' : $json)) {
    error_log('Billing audit failed: ' . $action);
  }
  if (!$notify) return [];
  $results = [];
  try {
    $recipients = billing_recipients($db, (int)$complaint['id']);
  } catch (Throwable $error) {
    error_log('Billing recipients failed: ' . $error->getMessage());
    return [];
  }
  $reference = (string)$complaint['reference_no'];
  $notice = billing_notice_body($event, $reference, $cents);
  $sms = billing_sms_body($event, $reference, $cents);
  $nowText = hearing_fmt(hearing_now());
  foreach ($recipients as $residentId) {
    try {
      complaint_party_exec($db,
        'INSERT INTO notifications (resident_id, title, body, is_read, created_at) VALUES (?, ?, ?, ?, ?)',
        [$residentId, BILLING_NOTICE_TITLE, $notice, 0, $nowText]);
    } catch (Throwable $error) {
      error_log('Billing notice failed: ' . $error->getMessage());
    }
    try {
      $sent = notify_resident($residentId, 'complaint_bill_' . $event, $sms, 'complaints_enabled');
    } catch (Throwable $error) {
      error_log('Billing SMS failed: ' . $error->getMessage());
      $sent = ['status' => 'failed'];
    }
    $results[] = ['resident_id' => $residentId, 'status' => (string)($sent['status'] ?? 'failed')];
  }
  return $results;
}

function billing_result(mysqli $db, int $complaintId, array $sms, ?bool $changed = null): array {
  $row = billing_bill_row($db, $complaintId);
  $out = ['bill' => $row === null ? null : billing_official_view($row), 'clear' => complaint_billing_clear($db, $complaintId), 'sms' => $sms];
  if ($changed !== null) $out['changed'] = $changed;
  return $out;
}

/** POST bill_issue.php {complaint_id, amount, billing_note?} */
function complaint_bill_issue(mysqli $db, array $input, int $officialId, string $officialName): array {
  complaint_party_keys($input, ['complaint_id', 'amount', 'billing_note']);
  $complaintId = hearing_int($input, 'complaint_id');
  $amount = billing_amount($input['amount'] ?? null);
  $note = billing_note_value($input['billing_note'] ?? null);
  billing_require_ready($db);

  $done = billing_transaction($db, static function () use ($db, $complaintId, $amount, $note, $officialId): array {
    [$complaint, $bill] = billing_lock($db, $complaintId, false);
    if (!billing_editable_status($complaint)) throw new ComplaintProblem(BILLING_MSG_ISSUE_STATUS, 409);
    if ($bill !== null) throw new ComplaintProblem(BILLING_MSG_EXISTS, 409);
    $nowText = hearing_fmt(hearing_now());
    complaint_party_exec($db,
      "INSERT INTO complaint_bills (complaint_id, amount, billing_note, status, issued_by, issued_at, version, created_at, updated_at)
       VALUES (?, ?, ?, 'unpaid', ?, ?, 1, ?, ?)",
      [$complaintId, $amount, $note, $officialId, $nowText, $nowText, $nowText]);
    hearing_timeline($db, $complaint, $officialId, BILLING_NOTE['issued'], $nowText, false);
    return ['complaint' => $complaint];
  });
  $sms = billing_after($db, 'issued', 'Issued complaint fee', $done['complaint'], billing_db_cents($amount), $officialId, $officialName,
    ['amount' => $amount, 'billing_note' => $note], true);
  return billing_result($db, $complaintId, $sms);
}

/** POST bill_update.php {complaint_id, version, amount, billing_note?}. A missing billing_note keeps the note. */
function complaint_bill_update(mysqli $db, array $input, int $officialId, string $officialName): array {
  complaint_party_keys($input, ['complaint_id', 'version', 'amount', 'billing_note']);
  $complaintId = hearing_int($input, 'complaint_id');
  $version = hearing_int($input, 'version');
  $amount = billing_amount($input['amount'] ?? null);
  $noteSent = array_key_exists('billing_note', $input);
  $note = $noteSent ? billing_note_value($input['billing_note']) : null;
  billing_require_ready($db);

  $done = billing_transaction($db, static function () use ($db, $complaintId, $version, $amount, $noteSent, $note, $officialId): array {
    [$complaint, $bill] = billing_lock($db, $complaintId, true);
    billing_check_open($bill, $version);
    if (!billing_editable_status($complaint)) throw new ComplaintProblem(BILLING_MSG_EDIT_STATUS, 409);
    $oldAmount = billing_from_cents(billing_db_cents($bill['amount']));
    $oldNote = $bill['billing_note'] === null ? null : (string)$bill['billing_note'];
    $newNote = $noteSent ? $note : $oldNote;
    $changes = [];
    if ($oldAmount !== $amount) $changes['amount'] = [$oldAmount, $amount];
    if ($oldNote !== $newNote) $changes['billing_note'] = [$oldNote, $newNote];
    if ($changes === []) return ['complaint' => $complaint, 'changes' => []];
    $affected = complaint_party_exec($db,
      'UPDATE complaint_bills SET amount = ?, billing_note = ?, version = version + 1, updated_at = ? WHERE id = ? AND version = ?',
      [$amount, $newNote, hearing_fmt(hearing_now()), (int)$bill['id'], $version]);
    if ($affected !== 1) throw new ComplaintProblem(COMPLAINT_PARTY_MSG_STALE, 409);
    return ['complaint' => $complaint, 'changes' => $changes];
  });
  if ($done['changes'] !== []) {
    billing_after($db, 'updated', 'Updated complaint fee', $done['complaint'], 0, $officialId, $officialName, ['changes' => $done['changes']], false);
  }
  return billing_result($db, $complaintId, [], $done['changes'] !== []);
}

/**
 * unpaid -> paid | waived. Allowed whatever the complaint status (D2: late payment).
 * $extra: ['receipt_no' => ?string] or ['waive_reason' => string].
 */
function billing_settle(mysqli $db, int $complaintId, int $version, string $status, array $extra, int $officialId): array {
  return billing_transaction($db, static function () use ($db, $complaintId, $version, $status, $extra, $officialId): array {
    [$complaint, $bill] = billing_lock($db, $complaintId, true);
    billing_check_open($bill, $version);
    $nowText = hearing_fmt(hearing_now());
    $affected = complaint_party_exec($db,
      'UPDATE complaint_bills SET status = ?, receipt_no = ?, waive_reason = ?, settled_by = ?, settled_at = ?,
              version = version + 1, updated_at = ? WHERE id = ? AND version = ?',
      [$status, $extra['receipt_no'] ?? null, $extra['waive_reason'] ?? null, $officialId, $nowText, $nowText, (int)$bill['id'], $version]);
    if ($affected !== 1) throw new ComplaintProblem(COMPLAINT_PARTY_MSG_STALE, 409);
    hearing_timeline($db, $complaint, $officialId, BILLING_NOTE[$status], $nowText, false);
    return ['complaint' => $complaint, 'cents' => billing_db_cents($bill['amount'])];
  });
}

/** POST bill_mark_paid.php {complaint_id, version, receipt_no?} */
function complaint_bill_mark_paid(mysqli $db, array $input, int $officialId, string $officialName): array {
  complaint_party_keys($input, ['complaint_id', 'version', 'receipt_no']);
  $complaintId = hearing_int($input, 'complaint_id');
  $version = hearing_int($input, 'version');
  $receipt = billing_receipt($input['receipt_no'] ?? null);
  billing_require_ready($db);
  $done = billing_settle($db, $complaintId, $version, 'paid', ['receipt_no' => $receipt], $officialId);
  $sms = billing_after($db, 'paid', 'Marked complaint fee paid', $done['complaint'], $done['cents'], $officialId, $officialName,
    ['amount' => billing_from_cents($done['cents']), 'receipt_no' => $receipt], true);
  return billing_result($db, $complaintId, $sms);
}

/** POST bill_waive.php {complaint_id, version, reason} */
function complaint_bill_waive(mysqli $db, array $input, int $officialId, string $officialName): array {
  complaint_party_keys($input, ['complaint_id', 'version', 'reason']);
  $complaintId = hearing_int($input, 'complaint_id');
  $version = hearing_int($input, 'version');
  $reason = hearing_reason($input['reason'] ?? null);
  billing_require_ready($db);
  $done = billing_settle($db, $complaintId, $version, 'waived', ['waive_reason' => $reason], $officialId);
  $sms = billing_after($db, 'waived', 'Waived complaint fee', $done['complaint'], $done['cents'], $officialId, $officialName,
    ['amount' => billing_from_cents($done['cents']), 'reason' => $reason], true);
  return billing_result($db, $complaintId, $sms);
}
