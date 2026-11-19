<?php
/**
 * BarangayLink - Official dashboard statistics tests (panel revision, D1).
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * Run: php project-support/tests/dashboard-stats/run.php
 *
 * Database: a scratch database named capstone_dashstats_*, built from
 * database/database.sql; its name is asserted before any write and it is
 * dropped at the end. The real database is never opened.
 *
 * Files: this suite reads and writes no template or generated document, so it
 * needs no TestStorage; only a session folder, a prepend file and a server log
 * in the system temp folder, all removed at the end.
 *
 * Figures are checked through dashboard_stats_data() with a fixed $now
 * (2026-11-17 10:00 Asia/Manila). The HTTP checks cover guards, the range
 * whitelist and the privacy canary (no seeded name, phone, address, venue,
 * location, description, purpose or reference number in any response body).
 */

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

foreach (['GEMINI_API_KEY', 'GEMINI_MODEL', 'HTTPSMS_API_KEY', 'HTTPSMS_FROM_NUMBER', 'HTTPSMS_SIM_SLOT'] as $blocked) {
    putenv($blocked . '='); $_ENV[$blocked] = ''; $_SERVER[$blocked] = '';
}

$root = dirname(__DIR__, 3);
require_once $root . '/api/common.php';
require_once $root . '/api/stats/_dashboard.php';
mysqli_report(MYSQLI_REPORT_OFF);

$realDatabase = $DB_NAME;
$prefix = 'capstone_dashstats_';
$scratch = $prefix . getmypid() . '_' . random_int(1000, 9999);
$work = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $scratch;
$passed = 0; $failed = 0; $servers = []; $admin = null; $db = null;

function ds_check(string $name, bool $ok, string $detail = ''): void {
    global $passed, $failed;
    if ($ok) { $passed++; echo "PASS {$name}\n"; }
    else { $failed++; echo "FAIL {$name}" . ($detail === '' ? '' : ' :: ' . $detail) . "\n"; }
}
/** Strict equality after a JSON round trip (what the browser receives). */
function ds_eq(string $name, mixed $actual, mixed $expected): void {
    $a = json_decode((string)json_encode($actual), true);
    $e = json_decode((string)json_encode($expected), true);
    ds_check($name, $a === $e, 'got ' . json_encode($actual) . ' expected ' . json_encode($expected));
}
function ds_count(mysqli $db, string $sql): int {
    $result = $db->query($sql);
    if (!$result) throw new RuntimeException($db->error);
    return (int)$result->fetch_row()[0];
}
function ds_exec(mysqli $db, string $sql): void {
    if (!$db->query($sql)) throw new RuntimeException($db->error . ' :: ' . substr($sql, 0, 160));
}
function ds_http(string $url, string $method = 'GET', array|string|null $body = null, ?string $cookie = null): array {
    $ch = curl_init($url); $headers = [];
    if ($cookie !== null) $headers[] = 'Cookie: ' . $cookie;
    if (is_string($body)) $headers[] = 'Content-Type: application/json';
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_POSTFIELDS => $body, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60]);
    $raw = (string)curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $size = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    $cookieOut = preg_match('/^Set-Cookie:\s*([^;\r\n]+)/mi', substr($raw, 0, $size), $m) ? $m[1] : '';
    $json = json_decode(substr($raw, $size), true);
    return ['status' => $status, 'body' => substr($raw, $size), 'json' => is_array($json) ? $json : [], 'cookie' => $cookieOut];
}
function ds_stop($process): void {
    if (!is_resource($process)) return;
    $state = proc_get_status($process); if ($state['running']) proc_terminate($process); proc_close($process);
}
function ds_rmtree(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $entry;
        is_dir($path) ? ds_rmtree($path) : @unlink($path);
    }
    @rmdir($dir);
}
function ds_zeros(int $n, mixed $zero = 0): array { return array_fill(0, $n, $zero); }

const DS_PAYLOAD_KEYS = ['range', 'months', 'kpis', 'requests_monthly', 'complaints_monthly', 'request_status', 'complaint_status',
    'households_mpi', 'requests_by_type', 'complaints_by_type', 'residents_age_groups', 'residents_by_purok', 'sectors',
    'collections_monthly', 'waived', 'mediation_outcomes', 'upcoming_mediations'];
const DS_KPI_KEYS = ['active_residents', 'new_residents_this_month', 'households_total', 'households_profiled', 'households_profiled_pct',
    'pending_requests', 'in_progress_requests', 'open_complaints', 'mediations_next_7_days', 'collected_this_month', 'collected_last_month'];
const DS_MONTHS_6M = ['2026-06', '2026-07', '2026-08', '2026-09', '2026-10', '2026-11'];
const DS_MONTHS_12M = ['2025-12', '2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10', '2026-11'];
const DS_MONTHS_YTD = ['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10', '2026-11'];

try {
    // --------------------------------------------------------------- setup
    ds_check('scratch database name differs from the real database', $scratch !== $realDatabase && str_starts_with($scratch, $prefix));
    $admin = new mysqli($DB_HOST, $DB_USER, $DB_PASS);
    ds_exec($admin, "CREATE DATABASE `{$scratch}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $scratch); $db->set_charset('utf8mb4');
    if ((string)$db->query('SELECT DATABASE()')->fetch_row()[0] !== $scratch) throw new RuntimeException('scratch database assertion failed');
    ds_check('scratch database prefix asserted before writes', str_starts_with((string)$db->query('SELECT DATABASE()')->fetch_row()[0], $prefix));
    // complaint_types_store() reads through get_db(): point it at the scratch database before the first call.
    $DB_NAME = $scratch;
    $sql = preg_replace('/^\xEF\xBB\xBF/', '', (string)file_get_contents($root . '/database/database.sql'));
    $sql = preg_replace('/CREATE DATABASE IF NOT EXISTS `[^`]+`.*?;\s*USE `[^`]+`;/s', '', $sql, 1) ?? $sql;
    if (!$db->multi_query($sql)) throw new RuntimeException($db->error);
    do { if ($r = $db->store_result()) $r->free(); } while ($db->more_results() && $db->next_result());
    if ($db->errno) throw new RuntimeException($db->error);
    ds_check('get_db() resolves to the scratch database', (string)get_db()->query('SELECT DATABASE()')->fetch_row()[0] === $scratch);
    ds_check('complaint billing tables are installed', complaint_billing_ready($db));

    $now = new DateTimeImmutable('2026-11-17 10:00:00', new DateTimeZone('Asia/Manila'));

    // ------------------------------------------------------- pure helpers
    ds_eq('window: 6m', dashboard_window('6m', $now)['months'], DS_MONTHS_6M);
    ds_eq('window: 12m', dashboard_window('12m', $now)['months'], DS_MONTHS_12M);
    ds_eq('window: ytd', dashboard_window('ytd', $now)['months'], DS_MONTHS_YTD);
    ds_eq('window: ytd in January is one month', dashboard_window('ytd', new DateTimeImmutable('2026-01-31 23:00:00', new DateTimeZone('Asia/Manila')))['months'], ['2026-01']);
    ds_eq('window: 6m on the 31st crosses short months correctly',
        dashboard_window('6m', new DateTimeImmutable('2026-08-31 12:00:00', new DateTimeZone('Asia/Manila')))['months'],
        ['2026-03', '2026-04', '2026-05', '2026-06', '2026-07', '2026-08']);
    $bad = false; try { dashboard_stats_data($db, 'abc', $now); } catch (InvalidArgumentException) { $bad = true; }
    ds_check('library: invalid range throws InvalidArgumentException', $bad);
    $many = [];
    foreach (range(1, 12) as $i) $many[] = ['label' => 'L' . str_pad((string)$i, 2, '0', STR_PAD_LEFT), 'count' => 20 - $i];
    $top = dashboard_top($many);
    // Counts L01=19 ... L12=8: the top 10 stay, L11 (9) + L12 (8) fold into Other.
    ds_check('top: 12 labels -> 10 + Other with the rest summed',
        count($top) === 11 && $top[0] === ['label' => 'L01', 'count' => 19] && $top[10] === ['label' => 'Other', 'count' => 17], json_encode($top));
    // Other=50 and L01=19+1 lead; L01..L09 fill the top 10; L10 (10) + L11 (9) + L12 (8) go into the existing Other.
    $withOther = dashboard_top(array_merge($many, [['label' => 'Other', 'count' => 50], ['label' => 'L01', 'count' => 1]]));
    ds_check('top: existing Other row absorbs the rest, duplicate labels merged',
        count($withOther) === 10 && $withOther[0] === ['label' => 'Other', 'count' => 77] && $withOther[1] === ['label' => 'L01', 'count' => 20],
        json_encode($withOther));
    ds_eq('top: ties sort by label', dashboard_top([['label' => 'b', 'count' => 1], ['label' => 'A', 'count' => 1], ['label' => 'c', 'count' => 2]]),
        [['label' => 'c', 'count' => 2], ['label' => 'A', 'count' => 1], ['label' => 'b', 'count' => 1]]);
    ds_check('cents: DECIMAL text is exact', dashboard_cents('150.50') === 15050 && dashboard_cents(null) === 0 && dashboard_cents('7') === 700
        && dashboard_money(15050 + 9999) === '250.49');

    // ----------------------------------------------------------- empty DB
    foreach (['6m' => DS_MONTHS_6M, '12m' => DS_MONTHS_12M, 'ytd' => DS_MONTHS_YTD] as $range => $months) {
        $empty = dashboard_stats_data($db, $range, $now);
        $n = count($months);
        ds_eq("empty {$range}: payload keys", array_keys($empty), DS_PAYLOAD_KEYS);
        ds_eq("empty {$range}: everything zero", $empty, [
            'range' => $range, 'months' => $months,
            'kpis' => ['active_residents' => 0, 'new_residents_this_month' => 0, 'households_total' => 0, 'households_profiled' => 0,
                'households_profiled_pct' => null, 'pending_requests' => 0, 'in_progress_requests' => 0, 'open_complaints' => 0,
                'mediations_next_7_days' => 0, 'collected_this_month' => '0.00', 'collected_last_month' => '0.00'],
            'requests_monthly' => ds_zeros($n), 'complaints_monthly' => ds_zeros($n),
            'request_status' => ['pending' => 0, 'processing' => 0, 'ready' => 0, 'completed' => 0, 'cancelled' => 0],
            'complaint_status' => ['open' => 0, 'investigating' => 0, 'resolved' => 0],
            'households_mpi' => ['poor' => 0, 'not_poor' => 0, 'incomplete' => 0, 'not_assessed' => 0],
            'requests_by_type' => [], 'complaints_by_type' => [],
            'residents_age_groups' => ['0-17' => 0, '18-59' => 0, '60+' => 0, 'unknown' => 0],
            'residents_by_purok' => [],
            'sectors' => ['pwd' => 0, 'solo_parent' => 0, 'four_ps' => 0, 'senior' => 0],
            'collections_monthly' => ['documents' => ds_zeros($n, '0.00'), 'complaints' => ds_zeros($n, '0.00')],
            'waived' => ['documents' => '0.00', 'complaints' => '0.00'],
            'mediation_outcomes' => ['settled' => 0, 'for_follow_up' => 0, 'referred' => 0, 'no_agreement' => 0],
            'upcoming_mediations' => [],
        ]);
    }

    // ---------------------------------------------------------- seed data
    $password = 'Dash-' . bin2hex(random_bytes(8)) . '!';
    $hash = $db->real_escape_string(password_hash($password, PASSWORD_DEFAULT));
    ds_exec($db, "INSERT INTO barangay_officials (fname,lname,username,role,address,contact,password,position,status) VALUES
        ('ZZOffFirstStaff','ZZOffLastStaff','dash-staff','staff','ZZOFFADDR Staff','+639171110081','{$hash}','Tester','active'),
        ('ZZOffFirstAdmin','ZZOffLastAdmin','dash-admin','admin','ZZOFFADDR Admin','+639171110082','{$hash}','Tester','active')");
    $staffId = ds_count($db, "SELECT id FROM barangay_officials WHERE username='dash-staff'");

    ds_exec($db, "INSERT INTO households (household_code,address,purok_zone,household_size,profile_status,mpi_status) VALUES
        ('DS-1','ZZHHADDR 1','Purok 1',1,'complete','poor'),
        ('DS-2','ZZHHADDR 2','Purok 1',1,'complete','not_poor'),
        ('DS-3','ZZHHADDR 3','Purok 2',1,'in_progress','incomplete'),
        ('DS-4','ZZHHADDR 4','Purok 2',1,'unprofiled',NULL),
        ('DS-5','ZZHHADDR 5','Purok 3',1,'complete',NULL),
        ('DS-6','ZZHHADDR 6','Purok 3',1,'unprofiled','poor')");
    $hh = ds_count($db, "SELECT id FROM households WHERE household_code='DS-1'");

    // name, birthdate, purok, pwd, solo, 4ps, status, created_at
    $people = [
        [1, "'2010-05-01'", "'Purok 1'", 1, 0, 0, 'active', '2026-11-03 08:00:00'],
        [2, "'1990-01-01'", "'Purok 1'", 0, 1, 1, 'active', '2026-10-15 08:00:00'],
        [3, "'1966-11-17'", "'  Purok 2 '", 0, 0, 0, 'active', '2026-11-17 09:00:00'],
        [4, "'1966-11-18'", "''", 0, 0, 1, 'active', '2026-01-01 08:00:00'],
        [5, 'NULL', 'NULL', 1, 0, 0, 'active', '2025-05-01 08:00:00'],
        [6, "'1940-01-01'", "'Purok 9'", 1, 1, 1, 'inactive', '2026-11-10 08:00:00'],
    ];
    foreach ($people as [$i, $birth, $purok, $pwd, $solo, $fourPs, $status, $created]) {
        ds_exec($db, "INSERT INTO residents (fname,lname,username,address,contact,password,status,household_id,birthdate,purok_zone,
                is_pwd,is_solo_parent,is_4ps_member,created_at)
            VALUES ('ZZResFirst{$i}','ZZResLast{$i}','dash-res-{$i}','ZZRESADDR {$i}','+63917222000{$i}','x','{$status}',{$hh},{$birth},{$purok},
                {$pwd},{$solo},{$fourPs},'{$created}')");
    }
    $resident = ds_count($db, "SELECT id FROM residents WHERE username='dash-res-1'");

    // type, amount, payment, status, date_requested, date_completed
    $requests = [
        ['Barangay Clearance', '100.00', 'paid', 'completed', 'Nov 02, 2026', "'2026-11-05 10:00:00'"],
        ['Barangay Clearance', '50.00', 'paid', 'completed', 'Oct 20, 2026', "'2026-10-25 09:00:00'"],
        ['Certificate of Residency', '30.00', 'waived', 'completed', 'Nov 03, 2026', "'2026-11-06 08:00:00'"],
        ['Certificate of Residency', '40.00', 'unpaid', 'pending', 'Nov 10, 2026', 'NULL'],
        ['Certificate of Indigency', '25.00', 'unpaid', 'processing', 'Sep 01, 2026', 'NULL'],
        ['Certificate of Indigency', '20.00', 'paid', 'ready', 'Aug 15, 2026', 'NULL'],
        ['Barangay Clearance', '60.00', 'paid', 'cancelled', 'Jul 01, 2026', 'NULL'],
        ['Summons', '500.00', 'paid', 'completed', 'Nov 01, 2026', "'2026-11-02 10:00:00'"],
        ['Barangay Clearance', '70.00', 'paid', 'completed', 'Jan 10, 2026', "'2026-01-12 10:00:00'"],
        ['Old Legacy Doc', '10.00', 'unpaid', 'pending', 'Dec 05, 2025', 'NULL'],
        ['Barangay Clearance', '10.00', 'unpaid', 'pending', 'not a date', 'NULL'],
        ['Barangay Clearance', '15.00', 'paid', 'completed', 'Dec 01, 2025', "'2025-12-03 10:00:00'"],
        ['Barangay Clearance', '5.00', 'paid', 'completed', 'May 31, 2026', "'2026-05-31 23:59:59'"],
    ];
    foreach ($requests as $i => [$type, $amount, $payment, $status, $requested, $completed]) {
        ds_exec($db, "INSERT INTO document_requests (reference_no,resident_id,resident_name,document_type,purpose,copies,total_amount,
                payment_status,date_requested,status,date_completed)
            VALUES ('ZZREF-REQ-{$i}',{$resident},'ZZResFirst1 ZZResLast1','{$type}','ZZPURPOSE {$i}',1,{$amount},'{$payment}','{$requested}','{$status}',{$completed})");
    }

    $typeId = static fn(string $code): int => ds_count($db, "SELECT id FROM complaint_types WHERE type_code='{$code}'");
    // key => [type code, status, date_filed, case_no]
    $complaints = [
        1 => ['noise_disturbance', 'open', 'Nov 01, 2026', "'BC-2026-0001'"],
        2 => ['noise_disturbance', 'investigating', 'Nov 05, 2026', "'BC-2026-0002'"],
        3 => ['road_infrastructure', 'resolved', 'Oct 10, 2026', "'BC-2026-0003'"],
        4 => ['flooding_drainage', 'open', 'Jan 05, 2026', 'NULL'],
        5 => ['public_safety', 'resolved', 'Dec 20, 2025', "'BC-2025-0005'"],
        6 => ['other', 'investigating', 'Jun 01, 2026', "'BC-2026-0006'"],
    ];
    $cid = [];
    foreach ($complaints as $k => [$code, $status, $filed, $caseNo]) {
        ds_exec($db, "INSERT INTO complaints (resident_id,reference_no,case_no,resident_name,filed_by_type,category,complaint_type_id,severity,
                location_text,description,date_filed,status)
            VALUES ({$resident},'ZZREF-CMP-{$k}',{$caseNo},'ZZResFirst2 ZZResLast2','resident','Category',{$typeId($code)},'medium',
                'ZZLOCATION {$k}','ZZDESCRIPTION {$k}','{$filed}','{$status}')");
        $cid[$k] = (int)$db->insert_id;
    }
    ds_exec($db, "INSERT INTO complaint_parties (complaint_id,role,resident_id,full_name,address,contact_no,created_by_type) VALUES
        ({$cid[1]},'complainant',NULL,'ZZPartyComplainant','ZZPARTYADDR 1','09171110098','system'),
        ({$cid[1]},'respondent',NULL,'ZZPartyRespondent','ZZPARTYADDR 2','09171110099','system')");

    // key => [complaint, session, starts_at, status, outcome]
    $hearings = [
        [1, 1, '2026-11-18 09:00:00', 'scheduled', 'NULL'],
        [2, 1, '2026-11-24 09:59:59', 'scheduled', 'NULL'],
        [3, 1, '2026-11-24 10:00:00', 'scheduled', 'NULL'],
        [1, 2, '2026-11-17 09:00:00', 'scheduled', 'NULL'],
        [3, 2, '2026-11-02 09:00:00', 'completed', "'settled'"],
        [3, 3, '2026-10-02 09:00:00', 'completed', "'for_follow_up'"],
        [6, 1, '2026-06-01 00:00:00', 'completed', "'referred'"],
        [6, 2, '2026-05-31 23:00:00', 'completed', "'no_agreement'"],
        [4, 1, '2026-11-20 09:00:00', 'cancelled', 'NULL'],
        [5, 1, '2025-12-01 09:00:00', 'completed', "'settled'"],
    ];
    foreach ($hearings as [$k, $session, $starts, $status, $outcome]) {
        $ends = (new DateTimeImmutable($starts))->modify('+1 hour')->format('Y-m-d H:i:s');
        ds_exec($db, "INSERT INTO complaint_hearings (complaint_id,session_no,starts_at,ends_at,venue,venue_key,presiding_official_id,status,outcome,
                created_by,updated_by)
            VALUES ({$cid[$k]},{$session},'{$starts}','{$ends}','ZZVENUE Hall','zzvenue hall',{$staffId},'{$status}',{$outcome},{$staffId},{$staffId})");
    }

    // key => [complaint, amount, status, settled_at]
    $bills = [
        [1, '200.00', 'paid', "'2026-11-10 12:00:00'"],
        [2, '150.50', 'paid', "'2026-10-01 00:00:00'"],
        [3, '80.00', 'waived', "'2026-11-11 08:00:00'"],
        [4, '300.00', 'unpaid', 'NULL'],
        [5, '99.99', 'paid', "'2026-03-15 08:00:00'"],
    ];
    foreach ($bills as [$k, $amount, $status, $settled]) {
        $settledBy = $settled === 'NULL' ? 'NULL' : (string)$staffId;
        ds_exec($db, "INSERT INTO complaint_bills (complaint_id,amount,status,issued_by,issued_at,settled_by,settled_at)
            VALUES ({$cid[$k]},{$amount},'{$status}',{$staffId},'2025-11-01 08:00:00',{$settledBy},{$settled})");
    }
    ds_check('seed rows created', ds_count($db, 'SELECT COUNT(*) FROM document_requests') === 13 && count($cid) === 6
        && ds_count($db, 'SELECT COUNT(*) FROM complaint_hearings') === 10 && ds_count($db, 'SELECT COUNT(*) FROM complaint_bills') === 5);

    // ------------------------------------------------------ seeded: 6m
    $common = [
        'kpis' => ['active_residents' => 5, 'new_residents_this_month' => 2, 'households_total' => 6, 'households_profiled' => 3,
            'households_profiled_pct' => 50.0, 'pending_requests' => 3, 'in_progress_requests' => 2, 'open_complaints' => 4,
            'mediations_next_7_days' => 2, 'collected_this_month' => '300.00', 'collected_last_month' => '200.50'],
        'request_status' => ['pending' => 3, 'processing' => 1, 'ready' => 1, 'completed' => 6, 'cancelled' => 1],
        'complaint_status' => ['open' => 2, 'investigating' => 2, 'resolved' => 2],
        'households_mpi' => ['poor' => 2, 'not_poor' => 1, 'incomplete' => 1, 'not_assessed' => 2],
        'residents_age_groups' => ['0-17' => 1, '18-59' => 2, '60+' => 1, 'unknown' => 1],
        'residents_by_purok' => [['label' => 'Purok 1', 'count' => 2], ['label' => 'Unassigned', 'count' => 2], ['label' => 'Purok 2', 'count' => 1]],
        'sectors' => ['pwd' => 2, 'solo_parent' => 1, 'four_ps' => 2, 'senior' => 1],
        'waived' => ['documents' => '30.00', 'complaints' => '80.00'],
        'upcoming_mediations' => [
            ['case_no' => 'BC-2026-0001', 'starts_at' => '2026-11-18 09:00:00', 'complaint_type' => 'Noise / Public Disturbance'],
            ['case_no' => 'BC-2026-0002', 'starts_at' => '2026-11-24 09:59:59', 'complaint_type' => 'Noise / Public Disturbance'],
        ],
    ];
    $expected = [
        '6m' => [
            'months' => DS_MONTHS_6M,
            'requests_monthly' => [0, 1, 1, 1, 1, 3],
            'complaints_monthly' => [1, 0, 0, 0, 1, 2],
            'requests_by_type' => [['label' => 'Barangay Clearance', 'count' => 3], ['label' => 'Certificate of Indigency', 'count' => 2],
                ['label' => 'Certificate of Residency', 'count' => 2]],
            'complaints_by_type' => [['label' => 'Noise / Public Disturbance', 'count' => 2], ['label' => 'Other', 'count' => 1],
                ['label' => 'Road / Infrastructure', 'count' => 1]],
            'collections_monthly' => ['documents' => ['0.00', '0.00', '0.00', '0.00', '50.00', '100.00'],
                'complaints' => ['0.00', '0.00', '0.00', '0.00', '150.50', '200.00']],
            'mediation_outcomes' => ['settled' => 1, 'for_follow_up' => 1, 'referred' => 1, 'no_agreement' => 0],
        ],
        '12m' => [
            'months' => DS_MONTHS_12M,
            'requests_monthly' => [2, 1, 0, 0, 0, 1, 0, 1, 1, 1, 1, 3],
            'complaints_monthly' => [1, 1, 0, 0, 0, 0, 1, 0, 0, 0, 1, 2],
            'requests_by_type' => [['label' => 'Barangay Clearance', 'count' => 6], ['label' => 'Certificate of Indigency', 'count' => 2],
                ['label' => 'Certificate of Residency', 'count' => 2], ['label' => 'Old Legacy Doc', 'count' => 1]],
            'complaints_by_type' => [['label' => 'Noise / Public Disturbance', 'count' => 2], ['label' => 'Flooding / Drainage', 'count' => 1],
                ['label' => 'Other', 'count' => 1], ['label' => 'Public Safety', 'count' => 1], ['label' => 'Road / Infrastructure', 'count' => 1]],
            'collections_monthly' => [
                'documents' => ['15.00', '70.00', '0.00', '0.00', '0.00', '5.00', '0.00', '0.00', '0.00', '0.00', '50.00', '100.00'],
                'complaints' => ['0.00', '0.00', '0.00', '99.99', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '150.50', '200.00']],
            'mediation_outcomes' => ['settled' => 2, 'for_follow_up' => 1, 'referred' => 1, 'no_agreement' => 1],
        ],
        'ytd' => [
            'months' => DS_MONTHS_YTD,
            'requests_monthly' => [1, 0, 0, 0, 1, 0, 1, 1, 1, 1, 3],
            'complaints_monthly' => [1, 0, 0, 0, 0, 1, 0, 0, 0, 1, 2],
            'requests_by_type' => [['label' => 'Barangay Clearance', 'count' => 5], ['label' => 'Certificate of Indigency', 'count' => 2],
                ['label' => 'Certificate of Residency', 'count' => 2]],
            'complaints_by_type' => [['label' => 'Noise / Public Disturbance', 'count' => 2], ['label' => 'Flooding / Drainage', 'count' => 1],
                ['label' => 'Other', 'count' => 1], ['label' => 'Road / Infrastructure', 'count' => 1]],
            'collections_monthly' => [
                'documents' => ['70.00', '0.00', '0.00', '0.00', '5.00', '0.00', '0.00', '0.00', '0.00', '50.00', '100.00'],
                'complaints' => ['0.00', '0.00', '99.99', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '150.50', '200.00']],
            'mediation_outcomes' => ['settled' => 1, 'for_follow_up' => 1, 'referred' => 1, 'no_agreement' => 1],
        ],
    ];
    foreach ($expected as $range => $figures) {
        $data = dashboard_stats_data($db, $range, $now);
        ds_eq("seeded {$range}: payload keys in contract order", array_keys($data), DS_PAYLOAD_KEYS);
        ds_eq("seeded {$range}: kpi keys", array_keys($data['kpis']), DS_KPI_KEYS);
        ds_eq("seeded {$range}: range echoed", $data['range'], $range);
        foreach ($figures + $common as $key => $value) ds_eq("seeded {$range}: {$key}", $data[$key], $value);
        ds_check("seeded {$range}: every monthly series has one value per month",
            count($data['requests_monthly']) === count($data['months']) && count($data['complaints_monthly']) === count($data['months'])
            && count($data['collections_monthly']['documents']) === count($data['months'])
            && count($data['collections_monthly']['complaints']) === count($data['months']));
        $types = array_column($data['requests_by_type'], 'label');
        ds_check("seeded {$range}: Summons (internal) excluded from every request figure",
            !in_array('Summons', $types, true) && !in_array('500.00', $data['collections_monthly']['documents'], true));
    }

    // ------------------------------------------------- January last month
    $jan = dashboard_stats_data($db, 'ytd', new DateTimeImmutable('2026-01-20 10:00:00', new DateTimeZone('Asia/Manila')));
    ds_check('ytd in January: one month, last month (December, outside the window) still collected',
        $jan['months'] === ['2026-01'] && $jan['kpis']['collected_this_month'] === '70.00' && $jan['kpis']['collected_last_month'] === '15.00'
        && $jan['collections_monthly']['documents'] === ['70.00'], json_encode([$jan['months'], $jan['kpis']]));

    // ------------------------------------------------------------ servers
    $sessions = $work . DIRECTORY_SEPARATOR . 'sessions';
    if (!mkdir($sessions, 0700, true)) throw new RuntimeException('session folder failed');
    $prepend = $work . DIRECTORY_SEPARATOR . 'prepend.php';
    file_put_contents($prepend, "<?php\n"
        . "foreach (['GEMINI_API_KEY', 'GEMINI_MODEL', 'HTTPSMS_API_KEY', 'HTTPSMS_FROM_NUMBER', 'HTTPSMS_SIM_SLOT'] as \$k) { putenv(\$k . '='); \$_ENV[\$k] = ''; \$_SERVER[\$k] = ''; }\n"
        . "if (!str_starts_with((string)getenv('BARANGAY_DB_NAME'), '" . $prefix . "')) { http_response_code(500); exit; }\n"
        . 'require_once ' . var_export($root . '/db.php', true) . "; \$DB_NAME = getenv('BARANGAY_DB_NAME');\n");
    $base = null;
    for ($attempt = 0; $attempt < 10 && $base === null; $attempt++) {
        $port = random_int(20000, 45000);
        $cmd = [PHP_BINARY, '-d', 'session.save_path=' . $sessions, '-d', 'auto_prepend_file=' . $prepend, '-S', '127.0.0.1:' . $port, '-t', $root];
        $env = ['BARANGAY_DB_NAME' => $scratch, 'SystemRoot' => getenv('SystemRoot') ?: 'C:\\Windows', 'PATH' => getenv('PATH') ?: '',
            'TEMP' => sys_get_temp_dir(), 'TMP' => sys_get_temp_dir()];
        $process = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $work . '/server.log', 'a'], 2 => ['file', $work . '/server.log', 'a']], $pipes, $root, $env);
        if (!is_resource($process)) continue;
        for ($i = 0; $i < 40; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
            if (is_resource($socket)) { fclose($socket); $servers[] = $process; $base = 'http://127.0.0.1:' . $port; break; }
            usleep(25000);
        }
        if ($base === null) ds_stop($process);
    }
    if ($base === null) throw new RuntimeException('test server did not start');
    $login = static function (string $username) use ($base, $password): string {
        $r = ds_http($base . '/pages/login.php', 'POST', ['action' => 'official-login', 'username' => $username, 'password' => $password]);
        return $r['status'] === 302 ? $r['cookie'] : '';
    };
    $staff = $login('dash-staff'); $adminCookie = $login('dash-admin');
    ds_check('staff and admin officials log in on the test server', $staff !== '' && $adminCookie !== '');

    $url = $base . '/api/stats/dashboard.php';
    ds_check('http: logged-out GET -> 401', ds_http($url)['status'] === 401);
    ds_check('http: logged-out POST -> 401', ds_http($url, 'POST', '{}')['status'] === 401);
    ds_check('http: POST as an official -> 405', ds_http($url, 'POST', '{}', $staff)['status'] === 405);
    $badRange = ds_http($url . '?range=abc', 'GET', null, $staff);
    ds_check('http: ?range=abc -> 422 Invalid range.', $badRange['status'] === 422 && ($badRange['json']['message'] ?? '') === 'Invalid range.', $badRange['body']);
    ds_check('http: ?range[]=6m -> 422', ds_http($url . '?range%5B%5D=6m', 'GET', null, $staff)['status'] === 422);
    $bodies = [];
    $default = ds_http($url, 'GET', null, $staff);
    $bodies[] = $default['body'];
    ds_check('http: staff GET without range -> 200, range 6m, 6 months',
        $default['status'] === 200 && ($default['json']['success'] ?? false) === true && ($default['json']['range'] ?? null) === '6m'
        && count($default['json']['months'] ?? []) === 6 && array_keys(array_diff_key($default['json'], ['success' => 1])) === DS_PAYLOAD_KEYS,
        substr($default['body'], 0, 300));
    foreach (['6m', '12m', 'ytd'] as $range) {
        foreach (['staff' => $staff, 'admin' => $adminCookie] as $who => $cookie) {
            $res = ds_http($url . '?range=' . $range, 'GET', null, $cookie);
            $bodies[] = $res['body'];
            ds_check("http: {$who} GET range={$range} -> 200 with that range",
                $res['status'] === 200 && ($res['json']['range'] ?? null) === $range, substr($res['body'], 0, 200));
        }
    }

    // ---------------------------------------------------------- privacy
    $canaries = ['ZZRes', 'ZZOff', 'ZZParty', 'ZZRESADDR', 'ZZHHADDR', 'ZZOFFADDR', 'ZZPARTYADDR', 'ZZVENUE', 'zzvenue', 'ZZLOCATION',
        'ZZDESCRIPTION', 'ZZPURPOSE', 'ZZREF', 'dash-res', 'dash-staff', 'dash-admin', '917222000', '9171110081', '9171110082',
        '9171110098', '9171110099', 'Tester'];
    $all = implode("\n", $bodies) . "\n" . json_encode(dashboard_stats_data($db, '12m', $now));
    $found = array_values(array_filter($canaries, static fn(string $c): bool => stripos($all, $c) !== false));
    ds_check('privacy: no seeded name, phone, address, venue, location, description, purpose, username or reference in any response',
        $found === [], implode(', ', $found));

    // ------------------------------------------- before complaint billing
    $legacy = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $scratch); $legacy->set_charset('utf8mb4');
    if (!str_starts_with((string)$legacy->query('SELECT DATABASE()')->fetch_row()[0], $prefix)) throw new RuntimeException('scratch assertion failed');
    ds_exec($legacy, 'DROP TABLE complaint_bills');
    $noBilling = dashboard_stats_data($legacy, '6m', $now);
    ds_check('without complaint billing: complaint money is 0.00 everywhere, documents unchanged, no error',
        $noBilling['collections_monthly']['complaints'] === ds_zeros(6, '0.00') && $noBilling['waived']['complaints'] === '0.00'
        && $noBilling['waived']['documents'] === '30.00' && $noBilling['kpis']['collected_this_month'] === '100.00'
        && $noBilling['kpis']['collected_last_month'] === '50.00', json_encode([$noBilling['kpis'], $noBilling['waived']]));
    $legacy->close();
} catch (Throwable $e) {
    $failed++;
    echo 'FAIL unexpected error :: ' . $e->getMessage() . "\n";
} finally {
    foreach ($servers as $process) ds_stop($process);
    if ($db instanceof mysqli) @$db->close();
    if ($admin instanceof mysqli) {
        if (str_starts_with($scratch, $prefix)) $admin->query("DROP DATABASE IF EXISTS `{$scratch}`");
        $left = ds_count($admin, "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE 'capstone\\_dashstats\\_%'");
        $admin->close();
        ds_check('no scratch database left behind', $left === 0);
    }
    ds_rmtree($work);
    ds_check('temp folder removed', !is_dir($work));
}

echo "Results: {$passed}/" . ($passed + $failed) . " passed.\n";
exit($failed === 0 ? 0 : 1);
