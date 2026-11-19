<?php
/**
 * BarangayLink - Document-request report + AI summary tests (Phase 6.1).
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * Run: php project-support/tests/document-report/run.php
 *
 * Database: a scratch database named capstone_docreport_*, built from
 * database/database.sql; its name is asserted before any write and it is
 * dropped at the end. The real database is never opened for writing.
 *
 * Files: this suite reads and writes no template or generated document, so it
 * needs no TestStorage; only a session folder, a prepend file and a log in the
 * system temp folder, all removed at the end.
 *
 * No real AI call is possible anywhere:
 * - CLI: provider keys are blanked before anything loads and the model is a stub.
 * - Server A: no key. Server B: a dummy key AND every curl_* function disabled,
 *   and its prepend refuses to serve if curl_init still exists.
 */

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Must run before db.php's load_env(), which skips keys already present.
foreach (['GEMINI_API_KEY', 'GEMINI_MODEL', 'HTTPSMS_API_KEY', 'HTTPSMS_FROM_NUMBER', 'HTTPSMS_SIM_SLOT'] as $blocked) {
    putenv($blocked . '='); $_ENV[$blocked] = ''; $_SERVER[$blocked] = '';
}

$root = dirname(__DIR__, 3);
require_once $root . '/api/common.php';
require_once $root . '/api/stats/_requests_report.php';
mysqli_report(MYSQLI_REPORT_OFF);

$realDatabase = $DB_NAME;
$prefix = 'capstone_docreport_';
$scratch = $prefix . getmypid() . '_' . random_int(1000, 9999);
$work = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $scratch;
$passed = 0; $failed = 0; $servers = []; $admin = null; $db = null;

function dr_check(string $name, bool $ok, string $detail = ''): void {
    global $passed, $failed;
    if ($ok) { $passed++; echo "PASS {$name}\n"; }
    else { $failed++; echo "FAIL {$name}" . ($detail === '' ? '' : ' :: ' . $detail) . "\n"; }
}
function dr_count(mysqli $db, string $sql): int {
    $result = $db->query($sql);
    if (!$result) throw new RuntimeException($db->error);
    return (int)$result->fetch_row()[0];
}
function dr_exec(mysqli $db, string $sql): void {
    if (!$db->query($sql)) throw new RuntimeException($db->error . ' :: ' . substr($sql, 0, 120));
}
function dr_http(string $url, string $method = 'GET', array|string|null $body = null, ?string $cookie = null): array {
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
function dr_stop($process): void {
    if (!is_resource($process)) return;
    $state = proc_get_status($process); if ($state['running']) proc_terminate($process); proc_close($process);
}
function dr_rmtree(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $entry;
        is_dir($path) ? dr_rmtree($path) : @unlink($path);
    }
    @rmdir($dir);
}
/** JSON round trip, so PHP values compare the way the HTTP body decodes. */
function dr_json(mixed $value): mixed { return json_decode((string)json_encode($value), true); }
function dr_row(array $report, string $name): ?array {
    foreach ($report['by_type'] as $row) if ($row['document_type'] === $name) return $row;
    return null;
}

try {
    // --------------------------------------------------------------- setup
    dr_check('provider key is blank in this process', trim((string)(getenv('GEMINI_API_KEY') ?: '')) === '');
    dr_check('scratch database name differs from the real database', $scratch !== $realDatabase && str_starts_with($scratch, $prefix));
    $admin = new mysqli($DB_HOST, $DB_USER, $DB_PASS);
    dr_exec($admin, "CREATE DATABASE `{$scratch}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $scratch); $db->set_charset('utf8mb4');
    if ((string)$db->query('SELECT DATABASE()')->fetch_row()[0] !== $scratch) throw new RuntimeException('scratch database assertion failed');
    dr_check('scratch database prefix asserted before writes', str_starts_with((string)$db->query('SELECT DATABASE()')->fetch_row()[0], $prefix));
    $sql = preg_replace('/^\xEF\xBB\xBF/', '', (string)file_get_contents($root . '/database/database.sql'));
    $sql = preg_replace('/CREATE DATABASE IF NOT EXISTS `[^`]+`.*?;\s*USE `[^`]+`;/s', '', $sql, 1) ?? $sql;
    if (!$db->multi_query($sql)) throw new RuntimeException($db->error);
    do { if ($r = $db->store_result()) $r->free(); } while ($db->more_results() && $db->next_result());
    if ($db->errno) throw new RuntimeException($db->error);

    $password = 'Report-' . bin2hex(random_bytes(8)) . '!';
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare("INSERT INTO barangay_officials (fname,lname,username,role,address,contact,password,position,status) VALUES ('Report','Tester','report-staff','staff','Purok 1','+639171110071',?,'Tester','active')");
    $stmt->bind_param('s', $hash); $stmt->execute(); $officialId = (int)$stmt->insert_id; $stmt->close();
    dr_exec($db, "INSERT INTO households (household_code,address,household_size) VALUES ('DR-1','Purok 1',1)");
    $householdId = (int)$db->insert_id;
    dr_exec($db, "INSERT INTO residents (fname,lname,username,address,contact,password,status,household_id,birthdate,civil_status) VALUES ('Report','Resident','report-resident','Purok 1','+639171110072','x','active',{$householdId},'1990-01-01','Single')");
    $residentId = (int)$db->insert_id;
    dr_check('scratch people created', $officialId > 0 && $residentId > 0);
    dr_exec($db, "INSERT INTO document_types (name, code, template_file, status, audience, sort_order, processing_days, requirements) VALUES
        ('Report Zero Type', 'report_zero', '', 'active', 'resident', 90, 1, NULL),
        ('Report Archived Type', 'report_archived', '', 'archived', 'resident', 91, 1, NULL)");

    // ------------------------------------------------------------ servers
    $sessions = $work . DIRECTORY_SEPARATOR . 'sessions';
    if (!mkdir($sessions, 0700, true)) throw new RuntimeException('session folder failed');
    $prependFor = static function (string $name, string $key, bool $refuseCurl) use ($work, $root, $scratch, $prefix): string {
        $path = $work . DIRECTORY_SEPARATOR . $name . '.php';
        file_put_contents($path, "<?php\n"
            . ($refuseCurl ? "if (function_exists('curl_init')) { http_response_code(500); exit; }\n" : '')
            . "foreach (['GEMINI_MODEL', 'HTTPSMS_API_KEY', 'HTTPSMS_FROM_NUMBER', 'HTTPSMS_SIM_SLOT'] as \$k) { putenv(\$k . '='); \$_ENV[\$k] = ''; \$_SERVER[\$k] = ''; }\n"
            . "putenv('GEMINI_API_KEY=" . $key . "'); \$_ENV['GEMINI_API_KEY'] = '" . $key . "'; \$_SERVER['GEMINI_API_KEY'] = '" . $key . "';\n"
            . "if (!str_starts_with((string)getenv('BARANGAY_DB_NAME'), '" . $prefix . "')) { http_response_code(500); exit; }\n"
            . 'require_once ' . var_export($root . '/db.php', true) . "; \$DB_NAME = getenv('BARANGAY_DB_NAME');\n");
        return $path;
    };
    $start = static function (string $prepend, array $extraIni) use (&$servers, $root, $sessions, $scratch, $work): string {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $port = random_int(20000, 45000);
            $cmd = array_merge([PHP_BINARY, '-d', 'session.save_path=' . $sessions, '-d', 'auto_prepend_file=' . $prepend], $extraIni,
                ['-S', '127.0.0.1:' . $port, '-t', $root]);
            $env = ['BARANGAY_DB_NAME' => $scratch, 'SystemRoot' => getenv('SystemRoot') ?: 'C:\\Windows', 'PATH' => getenv('PATH') ?: '',
                'TEMP' => sys_get_temp_dir(), 'TMP' => sys_get_temp_dir()];
            $process = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $work . '/server.log', 'a'], 2 => ['file', $work . '/server.log', 'a']], $pipes, $root, $env);
            if (!is_resource($process)) continue;
            for ($i = 0; $i < 40; $i++) {
                $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
                if (is_resource($socket)) { fclose($socket); $servers[] = $process; return 'http://127.0.0.1:' . $port; }
                usleep(25000);
            }
            dr_stop($process);
        }
        throw new RuntimeException('test server did not start');
    };
    $baseA = $start($prependFor('prepend_a', '', false), []);
    $baseB = $start($prependFor('prepend_b', 'report-test-dummy-not-a-key', true),
        ['-d', 'disable_functions=curl_init,curl_exec,curl_setopt,curl_setopt_array,curl_multi_init,curl_multi_exec']);
    $login = static function (string $base) use ($password): string {
        $r = dr_http($base . '/pages/login.php', 'POST', ['action' => 'official-login', 'username' => 'report-staff', 'password' => $password]);
        return $r['status'] === 302 ? $r['cookie'] : '';
    };
    $cookieA = $login($baseA); $cookieB = $login($baseB);
    dr_check('official login on both test servers (server B refuses to run with curl available)', $cookieA !== '' && $cookieB !== '');

    $docAi = '/api/ai/analyze_document_requests.php';
    $complaintAi = '/api/ai/analyze_complaints.php';
    $logRows = static fn(string $where = '1=1'): int => dr_count($db, "SELECT COUNT(*) FROM ai_request_log WHERE {$where}");
    $audits = static fn(string $action): int => dr_count($db, "SELECT COUNT(*) FROM audit_log WHERE action='" . $db->real_escape_string($action) . "'");

    // ---------------------------------------------- guards and no_data
    dr_check('report: 401 without a session', dr_http($baseA . '/api/stats/requests_report.php')['status'] === 401);
    dr_check('report: GET only (POST 405)', dr_http($baseA . '/api/stats/requests_report.php', 'POST', '{}', $cookieA)['status'] === 405);
    dr_check('AI: 401 without a session', dr_http($baseA . $docAi, 'POST', '{}')['status'] === 401);
    dr_check('AI: POST only (GET 405)', dr_http($baseA . $docAi, 'GET', null, $cookieA)['status'] === 405);
    $noData = dr_http($baseA . $docAi, 'POST', '{}', $cookieA);
    dr_check('AI: empty report answers no_data and spends no budget',
        $noData['status'] === 200 && ($noData['json']['reason'] ?? null) === 'no_data'
        && array_key_exists('analysis', $noData['json']) && $noData['json']['analysis'] === null && $logRows() === 0, $noData['body']);
    $empty = request_report_data($db);
    dr_check('empty report: every resident type listed with 0 requests, Summons absent',
        count($empty['by_type']) === 7 && dr_row($empty, 'Summons') === null && array_sum(array_column($empty['by_type'], 'request_count')) === 0);

    // ---------------------------------------------------------- seed data
    $requests = [
        ['Barangay Clearance', 2, '100.00', 'paid', 'completed', 'Nov 05, 2026'],
        ['Barangay Clearance', 1, '50.00', 'unpaid', 'pending', 'Nov 06, 2026'],
        ['Barangay Clearance', 1, '50.00', 'unpaid', 'cancelled', 'Nov 07, 2026'],
        ['Certificate of Indigency', 1, '30.00', 'waived', 'ready', 'Oct 10, 2026'],
        ['Report Archived Type', 1, '40.00', 'paid', 'completed', 'Oct 11, 2026'],
        ['Report Archived Type', 3, '120.00', 'paid', 'cancelled', 'Oct 12, 2026'],
        ['Barangay ID', 1, '25.00', 'unpaid', 'processing', 'Oct 13, 2026'],
        ['Summons', 1, '0.00', 'unpaid', 'pending', 'Oct 14, 2026'],
        ['Barangay Clearance', 1, '10.00', 'paid', 'completed', 'someday'],
    ];
    $stmt = $db->prepare("INSERT INTO document_requests (reference_no,resident_id,resident_name,document_type,purpose,copies,total_amount,payment_status,date_requested,status) VALUES (?,?,'Report Resident',?,'Report test',?,?,?,?,?)");
    foreach ($requests as $i => [$type, $copies, $amount, $payment, $status, $date]) {
        $ref = 'DR-' . ($i + 1);
        $stmt->bind_param('sisissss', $ref, $residentId, $type, $copies, $amount, $payment, $date, $status);
        if (!$stmt->execute()) throw new RuntimeException($stmt->error);
    }
    $stmt->close();
    dr_exec($db, "INSERT INTO complaints (resident_id,reference_no,resident_name,category,location_text,description,date_filed,status) VALUES ({$residentId},'DR-C1','Report Resident','Noise','Purok 1','Report test','Nov 05, 2026','open')");

    // ------------------------------------------------------------- figures
    $report = request_report_data($db);
    dr_check('totals: internal Summons row excluded and reported, cancelled kept in the count',
        $report['totals']['request_count'] === 8 && $report['totals']['internal_excluded_count'] === 1 && $report['totals']['cancelled_count'] === 2
        && $report['totals']['request_count'] + $report['totals']['internal_excluded_count'] === dr_count($db, 'SELECT COUNT(*) FROM document_requests'), json_encode($report['totals']));
    dr_check('totals: copies and every amount exclude cancelled requests',
        $report['totals']['total_copies'] === 7 && $report['totals']['gross_amount'] === '255.00' && $report['totals']['collected_amount'] === '150.00'
        && $report['totals']['pending_amount'] === '75.00' && $report['totals']['waived_amount'] === '30.00', json_encode($report['totals']));
    dr_check('totals: money collected for a later-cancelled request is shown on its own', $report['totals']['cancelled_paid_amount'] === '120.00');
    dr_check('totals: gross = collected + pending + waived',
        abs((float)$report['totals']['gross_amount'] - (float)$report['totals']['collected_amount'] - (float)$report['totals']['pending_amount'] - (float)$report['totals']['waived_amount']) < 0.001);
    dr_check('status_counts: five statuses plus other, internal excluded, sum = request_count',
        $report['status_counts'] === ['pending' => 1, 'processing' => 1, 'ready' => 1, 'completed' => 3, 'cancelled' => 2, 'other' => 0]
        && array_sum($report['status_counts']) === $report['totals']['request_count'], json_encode($report['status_counts']));

    $names = array_column($report['by_type'], 'document_type');
    dr_check('by_type: order is count desc, registered before unregistered, then name',
        $names === ['Barangay Clearance', 'Report Archived Type', 'Certificate of Indigency', 'Barangay ID', 'Business Permit Endorsement',
            'Certificate of Good Moral Character', 'Certificate of Residency', 'Report Zero Type'], json_encode($names));
    dr_check('by_type: internal Summons type never listed', !in_array('Summons', $names, true));
    $zero = dr_row($report, 'Report Zero Type');
    dr_check('by_type: a type with 0 requests appears with code, active status and no active version',
        $zero !== null && $zero['request_count'] === 0 && $zero['gross_amount'] === '0.00' && $zero['code'] === 'report_zero'
        && $zero['status'] === 'active' && $zero['has_active_version'] === false && $zero['registered'] === true, json_encode($zero));
    $archived = dr_row($report, 'Report Archived Type');
    dr_check('by_type: archived type with requests, its cancelled request out of copies and amounts',
        $archived !== null && $archived['status'] === 'archived' && $archived['request_count'] === 2 && $archived['cancelled_count'] === 1
        && $archived['total_copies'] === 1 && $archived['gross_amount'] === '40.00' && $archived['collected_amount'] === '40.00', json_encode($archived));
    $legacy = dr_row($report, 'Barangay ID');
    dr_check('by_type: legacy name not in the registry still appears, registered false',
        $legacy !== null && $legacy['registered'] === false && $legacy['type_id'] === null && $legacy['status'] === null
        && $legacy['has_active_version'] === null && $legacy['request_count'] === 1 && $legacy['gross_amount'] === '25.00', json_encode($legacy));
    $clearance = dr_row($report, 'Barangay Clearance');
    dr_check('by_type: seeded type with an active version and a cancelled request',
        $clearance !== null && $clearance['has_active_version'] === true && $clearance['request_count'] === 4 && $clearance['cancelled_count'] === 1
        && $clearance['total_copies'] === 4 && $clearance['gross_amount'] === '160.00' && $clearance['collected_amount'] === '110.00', json_encode($clearance));
    dr_check('by_type: per-type rows sum to the totals',
        array_sum(array_column($report['by_type'], 'request_count')) === $report['totals']['request_count']
        && array_sum(array_column($report['by_type'], 'cancelled_count')) === $report['totals']['cancelled_count']
        && array_sum(array_column($report['by_type'], 'total_copies')) === $report['totals']['total_copies']
        && abs(array_sum(array_map('floatval', array_column($report['by_type'], 'gross_amount'))) - (float)$report['totals']['gross_amount']) < 0.001
        && abs(array_sum(array_map('floatval', array_column($report['by_type'], 'collected_amount'))) - (float)$report['totals']['collected_amount']) < 0.001);
    dr_check('monthly: same rules, unparseable dates and internal rows left out',
        $report['monthly'] === [
            ['ym' => '2026-11', 'request_count' => 3, 'cancelled_count' => 1, 'total_copies' => 3, 'gross_amount' => '150.00', 'collected_amount' => '100.00', 'pending_amount' => '50.00', 'waived_amount' => '0.00'],
            ['ym' => '2026-10', 'request_count' => 4, 'cancelled_count' => 1, 'total_copies' => 3, 'gross_amount' => '95.00', 'collected_amount' => '40.00', 'pending_amount' => '25.00', 'waived_amount' => '30.00'],
        ], json_encode($report['monthly']));

    // ------------------------------------------------- one source of truth
    $httpReport = dr_http($baseA . '/api/stats/requests_report.php', 'GET', null, $cookieA);
    $body = $httpReport['json']; unset($body['success']);
    dr_check('drift: the report endpoint returns exactly request_report_data()', $httpReport['status'] === 200 && $body === dr_json($report), $httpReport['body']);

    $payload = request_report_ai_payload($report);
    dr_check('AI payload: only allowlisted keys and row fields',
        array_keys($payload) === ['totals', 'status_counts', 'by_type', 'monthly']
        && array_keys($payload['by_type'][0]) === ['document_type', 'status', 'has_active_version', 'registered', 'request_count', 'cancelled_count', 'total_copies', 'gross_amount', 'collected_amount']
        && !array_key_exists('code', $payload['by_type'][0]) && !array_key_exists('type_id', $payload['by_type'][0]));
    dr_check('AI payload: carries the new fields (zero-request type, archived status, cancelled figures, status counts)',
        $payload['totals']['cancelled_paid_amount'] === '120.00' && $payload['totals']['internal_excluded_count'] === 1
        && $payload['status_counts']['cancelled'] === 2 && in_array('Report Zero Type', array_column($payload['by_type'], 'document_type'), true)
        && in_array('archived', array_column($payload['by_type'], 'status'), true));
    $smuggled = request_report_ai_payload(['totals' => ['request_count' => 1, 'resident_name' => 'DR-SECRET'], 'future' => ['DR-SECRET'],
        'by_type' => [['document_type' => 'X', 'reference_no' => 'DR-SECRET']]]);
    dr_check('AI payload: unknown keys never reach the model', !str_contains((string)json_encode($smuggled), 'DR-SECRET'));

    // ------------------------------------------------------- stubbed model
    $calls = [];
    $reply = json_encode(['summary' => str_repeat('s', 700), 'most_requested_types' => ['a', 'b', 'c', 'd', 'e'],
        'patterns' => ['p1', 'p2', 'p3', 'p4', 'p5', 'p6'], 'recommendations' => [str_repeat('r', 300), 7, '', 'r2']]);
    $ok = static function (...$args) use (&$calls, $reply): array { $calls[] = $args; return ['success' => true, 'content' => $reply, 'model' => 'fake-model']; };
    $auditBefore = $audits(REQUEST_REPORT_AUDIT_ACTION);
    $result = request_report_ai_analysis($db, $report, $officialId, 'Report Tester', $ok);
    dr_check('stubbed model: called once with the payload, the constant prompt, JSON mode and 30 s',
        count($calls) === 1 && json_decode($calls[0][0], true) === dr_json($payload) && $calls[0][1] === REQUEST_REPORT_AI_PROMPT
        && $calls[0][2] === 'auto' && $calls[0][3] === true && $calls[0][4] === 30);
    dr_check('prompt: mentions zero-request types, archived types, cancelled amounts and active versions',
        str_contains(REQUEST_REPORT_AI_PROMPT, 'zero requests') && str_contains(REQUEST_REPORT_AI_PROMPT, 'archived')
        && str_contains(REQUEST_REPORT_AI_PROMPT, 'cancelled_paid_amount') && str_contains(REQUEST_REPORT_AI_PROMPT, 'has_active_version'));
    dr_check('stubbed model: response shape kept and every list capped and trimmed',
        $result['ok'] === true && $result['model'] === 'fake-model'
        && array_keys($result['analysis']) === ['summary', 'most_requested_types', 'patterns', 'recommendations']
        && mb_strlen($result['analysis']['summary']) === 600 && $result['analysis']['most_requested_types'] === ['a', 'b', 'c']
        && count($result['analysis']['patterns']) === 4 && $result['analysis']['recommendations'] === [str_repeat('r', 160), 'r2'], json_encode($result));
    $auditRow = $db->query("SELECT official_id, official_name, target_type, target_reference, details FROM audit_log WHERE action='" . REQUEST_REPORT_AUDIT_ACTION . "' ORDER BY id DESC LIMIT 1")->fetch_assoc();
    dr_check('stubbed model: exactly one audit row, no data in its details',
        $audits(REQUEST_REPORT_AUDIT_ACTION) === $auditBefore + 1 && (int)$auditRow['official_id'] === $officialId
        && $auditRow['target_type'] === 'document_request_report' && $auditRow['target_reference'] === null && $auditRow['details'] === '', json_encode($auditRow));
    $fail = static fn(...$args): array => ['success' => false, 'error' => 'Gemini returned HTTP 500.'];
    $failed502 = request_report_ai_analysis($db, $report, $officialId, 'Report Tester', $fail);
    dr_check('failing model: error passed on (endpoint answers 502) and no audit row',
        $failed502 === ['ok' => false, 'error' => 'Gemini returned HTTP 500.'] && $audits(REQUEST_REPORT_AUDIT_ACTION) === $auditBefore + 1);

    // ------------------------------------------- endpoint order over HTTP
    $notConfigured = dr_http($baseA . $docAi, 'POST', '{}', $cookieA);
    dr_check('AI off: 503 "AI analysis is not configured." before the throttle, no budget spent, no audit',
        $notConfigured['status'] === 503 && ($notConfigured['json']['message'] ?? '') === 'AI analysis is not configured.'
        && $logRows() === 0 && $audits(REQUEST_REPORT_AUDIT_ACTION) === $auditBefore + 1, $notConfigured['body']);
    dr_exec($db, "INSERT INTO ai_request_log (official_id, endpoint, household_count) VALUES ({$officialId}, 'seed', 200)");
    $logFull = $logRows();
    $throttled = dr_http($baseB . $docAi, 'POST', '{}', $cookieB);
    dr_check('budget used up: 429, nothing logged, no audit, model never reached',
        $throttled['status'] === 429 && $logRows() === $logFull && $audits(REQUEST_REPORT_AUDIT_ACTION) === $auditBefore + 1, $throttled['body']);
    $complaintThrottled = dr_http($baseB . $complaintAi, 'POST', '{}', $cookieB);
    dr_check('complaints AI: budget used up -> 429 before the model, nothing logged, no audit',
        $complaintThrottled['status'] === 429 && $logRows() === $logFull && $audits('Generated complaint AI summary') === 0, $complaintThrottled['body']);
    dr_exec($db, "DELETE FROM ai_request_log WHERE endpoint='seed'");
    $modelDown = dr_http($baseB . $docAi, 'POST', '{}', $cookieB);
    dr_check('budget left, model unreachable: 502, exactly one doc_request_report unit logged, no audit',
        $modelDown['status'] === 502 && $logRows("endpoint='doc_request_report' AND household_count=1 AND official_id={$officialId}") === 1
        && $logRows() === 1 && $audits(REQUEST_REPORT_AUDIT_ACTION) === $auditBefore + 1, $modelDown['body']);
    $complaintDown = dr_http($baseB . $complaintAi, 'POST', '{}', $cookieB);
    dr_check('complaints AI: one complaint_report unit logged under its own key, 502, no audit',
        $complaintDown['status'] === 502 && $logRows("endpoint='complaint_report' AND household_count=1") === 1
        && $audits('Generated complaint AI summary') === 0, $complaintDown['body']);

    // ------------------------------------------------- older installs
    $legacyDb = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $scratch); $legacyDb->set_charset('utf8mb4');
    dr_exec($legacyDb, 'SET FOREIGN_KEY_CHECKS=0');
    dr_exec($legacyDb, 'DROP TABLE document_template_analyses');
    dr_exec($legacyDb, 'DROP TABLE document_template_versions');
    $noVersions = request_report_data($legacyDb);
    dr_check('older install without the versions table: report loads, has_active_version null, totals unchanged',
        $noVersions['totals'] === $report['totals'] && array_unique(array_column(array_filter($noVersions['by_type'], static fn($r) => $r['registered'] === true), 'has_active_version')) === [null]);
    $legacyDb->close();
    $legacyDb = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $scratch); $legacyDb->set_charset('utf8mb4');
    dr_exec($legacyDb, 'SET FOREIGN_KEY_CHECKS=0');
    foreach (['document_type_fields', 'document_types'] as $table) {
        if (dr_count($legacyDb, "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='{$table}'") === 1) dr_exec($legacyDb, "DROP TABLE {$table}");
    }
    $noRegistry = request_report_data($legacyDb);
    dr_check('install without the registry: report loads, every row registered null, nothing can be excluded',
        $noRegistry['totals']['request_count'] === 9 && $noRegistry['totals']['internal_excluded_count'] === 0
        && array_unique(array_column($noRegistry['by_type'], 'registered')) === [null] && dr_row($noRegistry, 'Summons') !== null, json_encode($noRegistry['totals']));
    $legacyDb->close();
} catch (Throwable $e) {
    $failed++;
    echo 'FAIL unexpected error :: ' . $e->getMessage() . "\n";
} finally {
    foreach ($servers as $process) dr_stop($process);
    if ($db instanceof mysqli) @$db->close();
    if ($admin instanceof mysqli) {
        if (str_starts_with($scratch, $prefix)) $admin->query("DROP DATABASE IF EXISTS `{$scratch}`");
        $left = dr_count($admin, "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE 'capstone\\_docreport\\_%'");
        $admin->close();
        dr_check('no scratch database left behind', $left === 0);
    }
    dr_rmtree($work);
    dr_check('temp folder removed', !is_dir($work));
}

echo "Results: {$passed}/" . ($passed + $failed) . " passed.\n";
exit($failed === 0 ? 0 : 1);
