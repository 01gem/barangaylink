<?php
/**
 * BarangayLink - Official dashboard figures (panel revision: dashboard charts).
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * ONE function, dashboard_stats_data(), returns every figure the official
 * dashboard shows. api/stats/dashboard.php returns it as it is.
 *
 * RANGE. '6m' = the current month and the 5 before it, '12m' = the current
 * month and the 11 before it, 'ytd' = January of the current year to the
 * current month. 'months' lists them oldest first as 'YYYY-MM'; every monthly
 * series has one value per month, months without data are 0 (filled here, in
 * PHP). Every windowed query is bounded: >= first day of the first month and
 * < first day of the month after the current one, bound as parameters.
 *
 * TIME. $now defaults to hearing_now() (Asia/Manila). Month edges, ages and
 * the next-7-days window are all taken from $now, so tests pass a fixed one.
 *
 * REUSE. Request rows of internal-audience types (the complaint Summons) are
 * left out of every request figure through request_report_counted_sql(), the
 * same rule as the document-request report. Status counts come from
 * request_report_status_counts(). Complaint type names come from
 * complaint_report_type() (current name; unknown/NULL type -> 'Other').
 *
 * MONEY. Collected = document requests with payment_status 'paid' by
 * date_completed month (completion stamps the date and turns unpaid into
 * paid) plus complaint bills with status 'paid' by settled_at month. Waived
 * amounts are reported on their own in 'waived' and never counted as
 * collected. Amounts are summed as integer cents and returned as 'N.NN'.
 * Before the complaint-billing migration every complaint amount is '0.00'.
 *
 * PRIVACY. Counts, amounts, month keys, document/complaint type names, purok
 * labels and case numbers only. No resident, party or official names, phones,
 * addresses, venues, descriptions, notes or reference numbers.
 */

require_once __DIR__ . '/_requests_report.php';
require_once __DIR__ . '/_complaint_report.php';

const DASHBOARD_RANGES = ['6m', '12m', 'ytd'];
const DASHBOARD_TOP_LIMIT = 10;
const DASHBOARD_UPCOMING_LIMIT = 10;
const DASHBOARD_UPCOMING_DAYS = 7;
const DASHBOARD_OTHER = 'Other';
const DASHBOARD_REQUEST_DATE = "STR_TO_DATE(r.date_requested, '%b %d, %Y')";

/** DECIMAL text from SUM() ('123.45', NULL) -> integer cents. */
function dashboard_cents(mixed $value): int {
  $text = trim((string)($value ?? '0'));
  if ($text === '') return 0;
  if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/D', $text, $m)) throw new RuntimeException('Unexpected amount.');
  return (int)$m[1] * 100 + (int)str_pad($m[2] ?? '0', 2, '0');
}

function dashboard_money(int $cents): string {
  return request_report_money($cents / 100);
}

/**
 * Month window for a range.
 * @return array{months:list<string>, from:DateTimeImmutable, to:DateTimeImmutable, current:DateTimeImmutable}
 */
function dashboard_window(string $range, DateTimeImmutable $now): array {
  if (!in_array($range, DASHBOARD_RANGES, true)) throw new InvalidArgumentException('Invalid range.');
  $current = $now->setTime(0, 0, 0)->modify('first day of this month');
  $count = match ($range) { '6m' => 6, '12m' => 12, 'ytd' => (int)$current->format('n') };
  $from = $current->modify('-' . ($count - 1) . ' months');
  $months = [];
  for ($i = 0, $month = $from; $i < $count; $i++, $month = $month->modify('+1 month')) $months[] = $month->format('Y-m');
  return ['months' => $months, 'from' => $from, 'to' => $current->modify('+1 month'), 'current' => $current];
}

/** [ym => value] -> one value per month of $months (missing months -> $zero). */
function dashboard_fill(array $months, array $byMonth, mixed $zero = 0): array {
  return array_map(static fn(string $ym) => $byMonth[$ym] ?? $zero, $months);
}

/**
 * Merge rows by label, sort count DESC then label ASC, keep the top
 * DASHBOARD_TOP_LIMIT and fold the rest into 'Other' (added to an existing
 * 'Other' row when one is already in the top).
 */
function dashboard_top(array $rows): array {
  $merged = [];
  foreach ($rows as $row) $merged[$row['label']] = ($merged[$row['label']] ?? 0) + (int)$row['count'];
  $list = [];
  foreach ($merged as $label => $count) $list[] = ['label' => (string)$label, 'count' => $count];
  usort($list, static fn(array $a, array $b): int => [$b['count'], strtolower($a['label'])] <=> [$a['count'], strtolower($b['label'])]);
  $head = array_slice($list, 0, DASHBOARD_TOP_LIMIT);
  $rest = array_sum(array_column(array_slice($list, DASHBOARD_TOP_LIMIT), 'count'));
  if ($rest > 0) {
    $other = array_search(DASHBOARD_OTHER, array_column($head, 'label'), true);
    if ($other === false) $head[] = ['label' => DASHBOARD_OTHER, 'count' => $rest];
    else $head[$other]['count'] += $rest;
  }
  return $head;
}

/**
 * Collected and waived cents per month inside [from, to).
 * @return array{documents:array<string,int>, complaints:array<string,int>, waived_documents:int, waived_complaints:int}
 */
function dashboard_collections(mysqli $db, string $counted, string $from, string $to): array {
  $out = ['documents' => [], 'complaints' => [], 'waived_documents' => 0, 'waived_complaints' => 0];
  foreach (complaint_report_rows($db, "SELECT DATE_FORMAT(r.date_completed, '%Y-%m') AS ym, r.payment_status AS ps, SUM(r.total_amount) AS amount
      FROM document_requests r
     WHERE {$counted} AND r.status <> 'cancelled' AND r.payment_status IN ('paid', 'waived')
       AND r.date_completed >= ? AND r.date_completed < ?
     GROUP BY ym, r.payment_status", [$from, $to]) as $row) {
    $cents = dashboard_cents($row['amount']);
    if ($row['ps'] === 'paid') $out['documents'][(string)$row['ym']] = ($out['documents'][(string)$row['ym']] ?? 0) + $cents;
    else $out['waived_documents'] += $cents;
  }
  if (complaint_billing_ready($db)) {
    foreach (complaint_report_rows($db, "SELECT DATE_FORMAT(b.settled_at, '%Y-%m') AS ym, b.status, SUM(b.amount) AS amount
        FROM complaint_bills b
       WHERE b.status IN ('paid', 'waived') AND b.settled_at >= ? AND b.settled_at < ?
       GROUP BY ym, b.status", [$from, $to]) as $row) {
      $cents = dashboard_cents($row['amount']);
      if ($row['status'] === 'paid') $out['complaints'][(string)$row['ym']] = ($out['complaints'][(string)$row['ym']] ?? 0) + $cents;
      else $out['waived_complaints'] += $cents;
    }
  }
  return $out;
}

/** Every dashboard figure. Throws InvalidArgumentException for a bad range, RuntimeException when a query fails. */
function dashboard_stats_data(mysqli $db, string $range, ?DateTimeImmutable $now = null): array {
  $now = ($now ?? hearing_now())->setTimezone(hearing_tz());
  $window = dashboard_window($range, $now);
  $months = $window['months'];
  $from = $window['from']->format('Y-m-d H:i:s');
  $to = $window['to']->format('Y-m-d H:i:s');
  $fromDate = $window['from']->format('Y-m-d');
  $toDate = $window['to']->format('Y-m-d');
  $monthStart = $window['current']->format('Y-m-d H:i:s');
  $lastMonthStart = $window['current']->modify('-1 month')->format('Y-m-d H:i:s');
  $today = $now->format('Y-m-d');
  $nowText = $now->format('Y-m-d H:i:s');
  $weekEnd = $now->modify('+' . DASHBOARD_UPCOMING_DAYS . ' days')->format('Y-m-d H:i:s');
  $counted = request_report_counted_sql($db);
  $requestDate = DASHBOARD_REQUEST_DATE;
  $complaintDate = COMPLAINT_REPORT_DATE;

  // ----------------------------------------------------------- residents
  $res = complaint_report_rows($db, "SELECT COUNT(*) AS active,
      COALESCE(SUM(created_at >= ? AND created_at < ?), 0) AS new_this_month,
      COALESCE(SUM(birthdate IS NULL), 0) AS age_unknown,
      COALESCE(SUM(birthdate IS NOT NULL AND TIMESTAMPDIFF(YEAR, birthdate, ?) < 18), 0) AS age_0_17,
      COALESCE(SUM(TIMESTAMPDIFF(YEAR, birthdate, ?) BETWEEN 18 AND 59), 0) AS age_18_59,
      COALESCE(SUM(TIMESTAMPDIFF(YEAR, birthdate, ?) >= 60), 0) AS age_60,
      COALESCE(SUM(is_pwd = 1), 0) AS pwd,
      COALESCE(SUM(is_solo_parent = 1), 0) AS solo_parent,
      COALESCE(SUM(is_4ps_member = 1), 0) AS four_ps
    FROM residents WHERE status = 'active'", [$monthStart, $to, $today, $today, $today])[0];
  $purok = complaint_report_rows($db, "SELECT COALESCE(NULLIF(TRIM(purok_zone), ''), 'Unassigned') AS label, COUNT(*) AS count
    FROM residents WHERE status = 'active' GROUP BY label");

  // ---------------------------------------------------------- households
  $hh = complaint_report_rows($db, "SELECT COUNT(*) AS total,
      COALESCE(SUM(profile_status = 'complete'), 0) AS profiled,
      COALESCE(SUM(mpi_status = 'poor'), 0) AS poor,
      COALESCE(SUM(mpi_status = 'not_poor'), 0) AS not_poor,
      COALESCE(SUM(mpi_status = 'incomplete'), 0) AS incomplete,
      COALESCE(SUM(mpi_status IS NULL), 0) AS not_assessed
    FROM households")[0];
  $hhTotal = (int)$hh['total'];
  $hhProfiled = (int)$hh['profiled'];

  // ------------------------------------------------------------ requests
  $statusCounts = request_report_status_counts($db);
  $requestStatus = [];
  foreach (REQUEST_REPORT_STATUSES as $status) $requestStatus[$status] = (int)$statusCounts[$status];
  $requestsByMonth = [];
  foreach (complaint_report_rows($db, "SELECT DATE_FORMAT({$requestDate}, '%Y-%m') AS ym, COUNT(*) AS n
      FROM document_requests r WHERE {$counted} AND {$requestDate} >= ? AND {$requestDate} < ? GROUP BY ym",
      [$fromDate, $toDate]) as $row) $requestsByMonth[(string)$row['ym']] = (int)$row['n'];
  $requestsByType = complaint_report_rows($db, "SELECT r.document_type AS label, COUNT(*) AS count
    FROM document_requests r WHERE {$counted} AND {$requestDate} >= ? AND {$requestDate} < ? GROUP BY r.document_type",
    [$fromDate, $toDate]);

  // ---------------------------------------------------------- complaints
  $complaintStatus = ['open' => 0, 'investigating' => 0, 'resolved' => 0];
  foreach (complaint_report_rows($db, 'SELECT status, COUNT(*) AS n FROM complaints GROUP BY status') as $row) {
    if (array_key_exists((string)$row['status'], $complaintStatus)) $complaintStatus[(string)$row['status']] = (int)$row['n'];
  }
  $complaintsByMonth = [];
  foreach (complaint_report_rows($db, "SELECT DATE_FORMAT({$complaintDate}, '%Y-%m') AS ym, COUNT(*) AS n
      FROM complaints c WHERE {$complaintDate} >= ? AND {$complaintDate} < ? GROUP BY ym",
      [$fromDate, $toDate]) as $row) $complaintsByMonth[(string)$row['ym']] = (int)$row['n'];
  $complaintsByType = [];
  foreach (complaint_report_rows($db, "SELECT c.complaint_type_id AS tid, COUNT(*) AS n
      FROM complaints c WHERE {$complaintDate} >= ? AND {$complaintDate} < ? GROUP BY c.complaint_type_id",
      [$fromDate, $toDate]) as $row) {
    $complaintsByType[] = ['label' => complaint_report_type(complaint_report_tid($row['tid']))['category'], 'count' => (int)$row['n']];
  }

  // ----------------------------------------------------------- mediation
  $outcomes = array_fill_keys(HEARING_OUTCOMES, 0);
  foreach (complaint_report_rows($db, "SELECT outcome, COUNT(*) AS n FROM complaint_hearings
      WHERE status = 'completed' AND outcome IS NOT NULL AND starts_at >= ? AND starts_at < ? GROUP BY outcome",
      [$from, $to]) as $row) {
    if (array_key_exists((string)$row['outcome'], $outcomes)) $outcomes[(string)$row['outcome']] = (int)$row['n'];
  }
  $nextWeek = (int)complaint_report_rows($db, "SELECT COUNT(*) AS n FROM complaint_hearings
      WHERE status = 'scheduled' AND starts_at >= ? AND starts_at < ?", [$nowText, $weekEnd])[0]['n'];
  $upcoming = [];
  foreach (complaint_report_rows($db, "SELECT h.starts_at, c.case_no, c.complaint_type_id AS tid
      FROM complaint_hearings h JOIN complaints c ON c.id = h.complaint_id
     WHERE h.status = 'scheduled' AND h.starts_at >= ? AND h.starts_at < ?
     ORDER BY h.starts_at, h.id LIMIT ?", [$nowText, $weekEnd, DASHBOARD_UPCOMING_LIMIT]) as $row) {
    $upcoming[] = [
      'case_no' => (string)($row['case_no'] ?? ''),
      'starts_at' => (string)$row['starts_at'],
      'complaint_type' => complaint_report_type(complaint_report_tid($row['tid']))['category'],
    ];
  }

  // ---------------------------------------------------------- collections
  $inRange = dashboard_collections($db, $counted, $from, $to);
  // Last month can fall before the window ('ytd' in January), so it has its own query.
  $recent = dashboard_collections($db, $counted, $lastMonthStart, $to);
  $collected = static fn(string $ym): string => dashboard_money(($recent['documents'][$ym] ?? 0) + ($recent['complaints'][$ym] ?? 0));
  $moneyFill = static fn(array $byMonth): array => array_map('dashboard_money', dashboard_fill($months, $byMonth));

  return [
    'range' => $range,
    'months' => $months,
    'kpis' => [
      'active_residents' => (int)$res['active'],
      'new_residents_this_month' => (int)$res['new_this_month'],
      'households_total' => $hhTotal,
      'households_profiled' => $hhProfiled,
      'households_profiled_pct' => $hhTotal === 0 ? null : round($hhProfiled / $hhTotal * 100, 1),
      'pending_requests' => $requestStatus['pending'],
      'in_progress_requests' => $requestStatus['processing'] + $requestStatus['ready'],
      'open_complaints' => $complaintStatus['open'] + $complaintStatus['investigating'],
      'mediations_next_7_days' => $nextWeek,
      'collected_this_month' => $collected($window['current']->format('Y-m')),
      'collected_last_month' => $collected($window['current']->modify('-1 month')->format('Y-m')),
    ],
    'requests_monthly' => dashboard_fill($months, $requestsByMonth),
    'complaints_monthly' => dashboard_fill($months, $complaintsByMonth),
    'request_status' => $requestStatus,
    'complaint_status' => $complaintStatus,
    'households_mpi' => [
      'poor' => (int)$hh['poor'],
      'not_poor' => (int)$hh['not_poor'],
      'incomplete' => (int)$hh['incomplete'],
      'not_assessed' => (int)$hh['not_assessed'],
    ],
    'requests_by_type' => dashboard_top($requestsByType),
    'complaints_by_type' => dashboard_top($complaintsByType),
    'residents_age_groups' => [
      '0-17' => (int)$res['age_0_17'],
      '18-59' => (int)$res['age_18_59'],
      '60+' => (int)$res['age_60'],
      'unknown' => (int)$res['age_unknown'],
    ],
    'residents_by_purok' => dashboard_top($purok),
    'sectors' => [
      'pwd' => (int)$res['pwd'],
      'solo_parent' => (int)$res['solo_parent'],
      'four_ps' => (int)$res['four_ps'],
      'senior' => (int)$res['age_60'],
    ],
    'collections_monthly' => [
      'documents' => $moneyFill($inRange['documents']),
      'complaints' => $moneyFill($inRange['complaints']),
    ],
    'waived' => [
      'documents' => dashboard_money($inRange['waived_documents']),
      'complaints' => dashboard_money($inRange['waived_complaints']),
    ],
    'mediation_outcomes' => $outcomes,
    'upcoming_mediations' => $upcoming,
  ];
}
