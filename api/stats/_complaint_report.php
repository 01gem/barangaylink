<?php
/**
 * BarangayLink - Complaints report figures (complaint revision Phase 8).
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * ONE place for every complaints-report query. api/stats/complaints_report.php
 * returns complaint_report_data() as it is; api/ai/analyze_complaints.php sends
 * only complaint_ai_payload() of it to the model.
 *
 * TYPES. Rows are grouped by complaints.complaint_type_id; 'category' is the
 * CURRENT type name from complaint_types_all(true), so a renamed type is one
 * row. Inactive types keep their name with is_active false. A NULL type id
 * (should not exist) groups under 'Other' with type_id null.
 *
 * DATES. complaints.date_filed is text 'M d, Y'; rows STR_TO_DATE cannot parse
 * are left out of the monthly figures and the days-to-first-session average.
 *
 * TIME. needs_recording uses hearing_now() (Asia/Manila), the same rule as
 * hearing_needs_recording(): scheduled and ends_at before now.
 *
 * PRIVACY. Counts, rates and type names only. No names, addresses, contacts,
 * notes, summaries, reasons, reference numbers or case numbers.
 *
 * BILLING (Phase 10). 'billing' holds counts and DECIMAL totals as 'N.NN'
 * strings; it is left out before the 20261120 migration. complaint_ai_payload()
 * passes only its six aggregate keys (no names, receipts, reasons or references).
 */

require_once __DIR__ . '/../complaints/_extra_fields.php';
require_once __DIR__ . '/../hearings/_hearings.php';
require_once __DIR__ . '/../complaints/_billing.php';

const COMPLAINT_REPORT_DATE = "STR_TO_DATE(c.date_filed, '%b %d, %Y')";
const COMPLAINT_REPORT_OTHER = 'Other';
/** Phase 10: the only billing keys the AI receives (aggregates). */
const COMPLAINT_AI_BILLING_KEYS = ['bills_issued', 'unpaid_count', 'unpaid_total', 'paid_count', 'paid_total', 'waived_count'];

function complaint_report_rows(mysqli $db, string $sql, array $params = []): array {
  if ($params === []) {
    $result = $db->query($sql);
    if (!$result) throw new RuntimeException('Complaint report query failed: ' . $db->error);
    return $result->fetch_all(MYSQLI_ASSOC);
  }
  return complaint_party_rows($db, $sql, $params);
}

/** type id (or null) -> ['type_id', 'category', 'is_active'] with the current name. */
function complaint_report_type(?int $typeId): array {
  $byId = [];   // complaint_types_store() caches per request; no second cache here
  foreach (complaint_types_all(true) as $type) $byId[$type['id']] = $type;
  if ($typeId === null || !isset($byId[$typeId])) {
    return ['type_id' => $typeId, 'category' => COMPLAINT_REPORT_OTHER, 'is_active' => true];
  }
  return ['type_id' => $typeId, 'category' => $byId[$typeId]['type_name'], 'is_active' => $byId[$typeId]['is_active']];
}

function complaint_report_tid(mixed $value): ?int {
  return $value === null ? null : (int)$value;
}

/** Sort type rows: $field DESC, then name (case-insensitive) ASC. */
function complaint_report_sort(array $rows, string $field): array {
  usort($rows, static fn(array $a, array $b): int => [$b[$field], strtolower($a['category'])] <=> [$a[$field], strtolower($b['category'])]);
  return $rows;
}

function complaint_report_rate(int $part, int $whole): ?float {
  return $whole === 0 ? null : round($part / $whole * 100, 1);
}

function complaint_report_avg(mixed $value): ?float {
  return $value === null ? null : round((float)$value, 1);
}

/** All-time session counts per HEARING_STATUSES and completed-session outcomes per HEARING_OUTCOMES, ints. */
function complaint_report_mediation_counts(mysqli $db): array {
  $sessions = array_fill_keys(HEARING_STATUSES, 0);
  foreach (complaint_report_rows($db, 'SELECT status, COUNT(*) AS n FROM complaint_hearings GROUP BY status') as $row) {
    if (array_key_exists((string)$row['status'], $sessions)) $sessions[(string)$row['status']] = (int)$row['n'];
  }
  $outcomes = array_fill_keys(HEARING_OUTCOMES, 0);
  foreach (complaint_report_rows($db, "SELECT outcome, COUNT(*) AS n FROM complaint_hearings
      WHERE status='completed' AND outcome IS NOT NULL GROUP BY outcome") as $row) {
    if (array_key_exists((string)$row['outcome'], $outcomes)) $outcomes[(string)$row['outcome']] = (int)$row['n'];
  }
  return ['sessions' => $sessions, 'outcomes' => $outcomes];
}

/** Every figure of the complaints report. Throws RuntimeException when a query fails. */
function complaint_report_data(mysqli $db, ?DateTimeImmutable $now = null): array {
  $date = COMPLAINT_REPORT_DATE;

  // --- Old keys -------------------------------------------------------------
  $totals = complaint_report_rows($db, "
    SELECT COUNT(*) AS total,
           SUM(CASE WHEN status='open'          THEN 1 ELSE 0 END) AS open,
           SUM(CASE WHEN status='investigating' THEN 1 ELSE 0 END) AS investigating,
           SUM(CASE WHEN status='resolved'      THEN 1 ELSE 0 END) AS resolved
      FROM complaints")[0] ?? [];

  $byCategory = [];
  foreach (complaint_report_rows($db, 'SELECT complaint_type_id AS tid, COUNT(*) AS n FROM complaints GROUP BY complaint_type_id') as $row) {
    $byCategory[] = complaint_report_type(complaint_report_tid($row['tid'])) + ['count' => (int)$row['n']];
  }
  $byCategory = complaint_report_sort($byCategory, 'count');

  $bySeverity = complaint_report_rows($db, 'SELECT severity, COUNT(*) AS count FROM complaints GROUP BY severity');

  $cross = [];
  foreach (complaint_report_rows($db, 'SELECT complaint_type_id AS tid, severity, COUNT(*) AS n
      FROM complaints GROUP BY complaint_type_id, severity ORDER BY complaint_type_id, severity') as $row) {
    $cross[] = complaint_report_type(complaint_report_tid($row['tid'])) + ['severity' => (string)$row['severity'], 'count' => (int)$row['n']];
  }

  // Ranked in PHP so the tie-break (name ASC) uses the current type name.
  $months = [];
  foreach (complaint_report_rows($db, "SELECT DATE_FORMAT({$date}, '%Y-%m') AS ym, c.complaint_type_id AS tid, COUNT(*) AS n
      FROM complaints c WHERE {$date} IS NOT NULL GROUP BY ym, c.complaint_type_id") as $row) {
    $months[(string)$row['ym']][] = complaint_report_type(complaint_report_tid($row['tid'])) + ['count' => (int)$row['n']];
  }
  krsort($months, SORT_STRING);
  $monthlyRank = [];
  foreach ($months as $ym => $rows) {
    foreach (array_slice(complaint_report_sort($rows, 'count'), 0, 5) as $i => $row) {
      $monthlyRank[] = ['ym' => (string)$ym, 'rn' => $i + 1] + $row;
    }
  }

  $monthly = complaint_report_rows($db, "
    SELECT DATE_FORMAT({$date}, '%Y-%m') AS ym,
           COUNT(*) AS total,
           SUM(CASE WHEN c.severity='critical' THEN 1 ELSE 0 END) AS critical_count,
           SUM(CASE WHEN c.severity='high'     THEN 1 ELSE 0 END) AS high_count
      FROM complaints c
     WHERE {$date} IS NOT NULL
     GROUP BY ym ORDER BY ym DESC LIMIT 12");

  // --- New keys -------------------------------------------------------------
  $byTypeStatus = [];
  foreach (complaint_report_rows($db, "SELECT complaint_type_id AS tid,
        SUM(status='open') AS o, SUM(status='investigating') AS i, SUM(status='resolved') AS r, COUNT(*) AS t
      FROM complaints GROUP BY complaint_type_id") as $row) {
    $byTypeStatus[] = complaint_report_type(complaint_report_tid($row['tid']))
      + ['open' => (int)$row['o'], 'investigating' => (int)$row['i'], 'resolved' => (int)$row['r'], 'total' => (int)$row['t']];
  }
  $byTypeStatus = complaint_report_sort($byTypeStatus, 'total');

  $filingRow = complaint_report_rows($db, "SELECT SUM(filed_by_type='resident') AS online, SUM(filed_by_type='official') AS walk_in FROM complaints")[0];
  $filing = ['online' => (int)$filingRow['online'], 'walk_in' => (int)$filingRow['walk_in']];

  $partyRow = complaint_report_rows($db, "SELECT
      (SELECT COUNT(*) FROM complaints) AS total,
      (SELECT COUNT(DISTINCT p.complaint_id) FROM complaint_parties p WHERE p.role='respondent' AND p.is_active=1) AS with_r,
      (SELECT COUNT(*) FROM complaint_parties p WHERE p.role='respondent' AND p.is_active=1 AND p.resident_id IS NOT NULL) AS reg,
      (SELECT COUNT(*) FROM complaint_parties p WHERE p.role='respondent' AND p.is_active=1 AND p.resident_id IS NULL) AS unreg")[0];
  $parties = [
    'with_respondent' => (int)$partyRow['with_r'],
    'without_respondent' => (int)$partyRow['total'] - (int)$partyRow['with_r'],
    'registered_respondents' => (int)$partyRow['reg'],
    'unregistered_respondents' => (int)$partyRow['unreg'],
  ];

  ['sessions' => $sessions, 'outcomes' => $outcomes] = complaint_report_mediation_counts($db);

  // First session = the lowest session_no of each complaint, whatever its status.
  $firstSessions = "SELECT h.complaint_id, DATEDIFF(DATE(h.starts_at), {$date}) AS days, c.complaint_type_id AS tid
      FROM complaint_hearings h
      JOIN complaints c ON c.id = h.complaint_id
      JOIN (SELECT complaint_id, MIN(session_no) AS first_no FROM complaint_hearings GROUP BY complaint_id) f
        ON f.complaint_id = h.complaint_id AND f.first_no = h.session_no
     WHERE {$date} IS NOT NULL";
  $avgAll = complaint_report_rows($db, "SELECT AVG(days) AS d FROM ({$firstSessions}) fs")[0]['d'];

  $nowText = hearing_fmt($now ?? hearing_now());
  $needs = (int)complaint_report_rows($db, "SELECT COUNT(*) AS n FROM complaint_hearings WHERE status='scheduled' AND ends_at < ?", [$nowText])[0]['n'];

  $caseNos = (int)complaint_report_rows($db, 'SELECT COUNT(*) AS n FROM complaints WHERE case_no IS NOT NULL')[0]['n'];

  $summons = ['current' => 0, 'superseded' => 0];
  $hasDocs = (int)complaint_report_rows($db, "SELECT COUNT(*) AS n FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_name = 'complaint_documents'")[0]['n'] === 1;
  if ($hasDocs) {
    foreach (complaint_report_rows($db, "SELECT status, COUNT(*) AS n FROM complaint_documents WHERE kind='summons' GROUP BY status") as $row) {
      if (array_key_exists((string)$row['status'], $summons)) $summons[(string)$row['status']] = (int)$row['n'];
    }
  }

  $mediation = [
    'complaints_with_case_no' => $caseNos,
    'sessions' => $sessions,
    'outcomes' => $outcomes,
    'settlement_rate' => complaint_report_rate($outcomes['settled'], $sessions['completed']),
    'avg_days_to_first_session' => complaint_report_avg($avgAll),
    'needs_recording' => $needs,
    'summons_issued' => $summons['current'],
    'summons_superseded' => $summons['superseded'],
  ];

  $avgByType = [];
  foreach (complaint_report_rows($db, "SELECT tid, AVG(days) AS d FROM ({$firstSessions}) fs GROUP BY tid") as $row) {
    $avgByType[(string)$row['tid']] = complaint_report_avg($row['d']);
  }
  $mediationByType = [];
  foreach (complaint_report_rows($db, "SELECT c.complaint_type_id AS tid,
        SUM(h.status='completed') AS done, SUM(h.status='completed' AND h.outcome='settled') AS settled
      FROM complaint_hearings h JOIN complaints c ON c.id = h.complaint_id
      GROUP BY c.complaint_type_id") as $row) {
    $mediationByType[] = complaint_report_type(complaint_report_tid($row['tid'])) + [
      'sessions_completed' => (int)$row['done'],
      'settled' => (int)$row['settled'],
      'avg_days_to_first_session' => $avgByType[(string)$row['tid']] ?? null,
    ];
  }
  $mediationByType = complaint_report_sort($mediationByType, 'sessions_completed');

  $report = [
    'totals' => $totals,
    'by_category' => $byCategory,
    'by_severity' => $bySeverity,
    'cross' => $cross,
    'monthly_rank' => $monthlyRank,
    'monthly' => $monthly,
    'by_type_status' => $byTypeStatus,
    'filing' => $filing,
    'parties' => $parties,
    'mediation' => $mediation,
    'mediation_by_type' => $mediationByType,
  ];
  if (complaint_billing_ready($db)) $report['billing'] = complaint_report_billing($db);
  return $report;
}

/** Phase 10: bills issued, unpaid / paid counts and totals, waived count. Totals stay DECIMAL text. */
function complaint_report_billing(mysqli $db): array {
  $row = complaint_report_rows($db, "SELECT COUNT(*) AS issued,
      COALESCE(SUM(status = 'unpaid'), 0) AS unpaid_count,
      COALESCE(SUM(CASE WHEN status = 'unpaid' THEN amount END), 0.00) AS unpaid_total,
      COALESCE(SUM(status = 'paid'), 0) AS paid_count,
      COALESCE(SUM(CASE WHEN status = 'paid' THEN amount END), 0.00) AS paid_total,
      COALESCE(SUM(status = 'waived'), 0) AS waived_count
    FROM complaint_bills")[0];
  // SUM(DECIMAL(10,2)) is DECIMAL(32,2) text: kept as text (can exceed the 6-digit input rule).
  $money = static function ($value): string {
    $text = (string)$value;
    if (!preg_match('/^\d+\.\d{2}$/D', $text)) throw new RuntimeException('Unexpected billing total.');
    return $text;
  };
  return [
    'bills_issued' => (int)$row['issued'],
    'unpaid_count' => (int)$row['unpaid_count'],
    'unpaid_total' => $money($row['unpaid_total']),
    'paid_count' => (int)$row['paid_count'],
    'paid_total' => $money($row['paid_total']),
    'waived_count' => (int)$row['waived_count'],
  ];
}

/** Copy only the listed fields of each row (missing ones become null). */
function complaint_report_pick(array $row, array $fields): array {
  $out = [];
  foreach ($fields as $field) $out[$field] = $row[$field] ?? null;
  return $out;
}

/**
 * What the AI model receives: an explicit allowlist of keys and row fields, so
 * a key added to the report later never reaches the model by accident.
 */
function complaint_ai_payload(array $report): array {
  $list = static fn(string $key, array $fields): array => array_map(
    static fn($row): array => complaint_report_pick(is_array($row) ? $row : [], $fields),
    array_values(is_array($report[$key] ?? null) ? $report[$key] : []));
  $mediation = is_array($report['mediation'] ?? null) ? $report['mediation'] : [];
  return [
    'totals' => complaint_report_pick(is_array($report['totals'] ?? null) ? $report['totals'] : [], ['total', 'open', 'investigating', 'resolved']),
    'by_category' => $list('by_category', ['category', 'is_active', 'count']),
    'by_severity' => $list('by_severity', ['severity', 'count']),
    'cross' => $list('cross', ['category', 'severity', 'count']),
    'monthly' => $list('monthly', ['ym', 'total', 'critical_count', 'high_count']),
    'filing' => complaint_report_pick(is_array($report['filing'] ?? null) ? $report['filing'] : [], ['online', 'walk_in']),
    'parties' => complaint_report_pick(is_array($report['parties'] ?? null) ? $report['parties'] : [],
      ['with_respondent', 'without_respondent', 'registered_respondents', 'unregistered_respondents']),
    'mediation' => complaint_report_pick($mediation, ['complaints_with_case_no', 'settlement_rate', 'avg_days_to_first_session',
        'needs_recording', 'summons_issued', 'summons_superseded'])
      + ['sessions' => complaint_report_pick(is_array($mediation['sessions'] ?? null) ? $mediation['sessions'] : [], HEARING_STATUSES)]
      + ['outcomes' => complaint_report_pick(is_array($mediation['outcomes'] ?? null) ? $mediation['outcomes'] : [], HEARING_OUTCOMES)],
    'mediation_by_type' => $list('mediation_by_type', ['category', 'sessions_completed', 'settled', 'avg_days_to_first_session']),
  ] + (is_array($report['billing'] ?? null)
    ? ['billing' => complaint_report_pick($report['billing'], COMPLAINT_AI_BILLING_KEYS)]
    : []);
}
