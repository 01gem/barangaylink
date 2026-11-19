<?php
/**
 * BarangayLink - Complaint billing suite (complaint revision Phase 10).
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * Over HTTP (php -S) on a scratch database built from database/database.sql,
 * with its own fixtures. Sections a-i of docs/archive/complaint-revision-phase10.md, plus
 * pure-helper checks of api/complaints/_billing.php. Templates and generated
 * summons live in TestStorage (%TEMP%), never in document_templates/ or
 * generated_documents/. No AI, no SMS provider (keys blanked: notify_resident
 * still writes an sms_log row per recipient, status 'skipped'). Everything it
 * creates is dropped with the scratch database / temp storage.
 * Needs LibreOffice (one summons is generated after payment).
 *
 *   php project-support/tests/complaint-billing/run.php
 */

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
foreach (['GEMINI_API_KEY', 'GEMINI_MODEL', 'HTTPSMS_API_KEY', 'HTTPSMS_FROM_NUMBER'] as $blocked) {
    putenv($blocked . '='); $_ENV[$blocked] = ''; $_SERVER[$blocked] = '';
}
$root = dirname(__DIR__, 3);
require_once $root . '/api/common.php';
require_once $root . '/api/hearings/_summons.php';
require_once $root . '/api/complaints/_billing.php';
require_once $root . '/api/stats/_complaint_report.php';
require_once $root . '/project-support/tests/_storage.php';
mysqli_report(MYSQLI_REPORT_OFF);

$storage = new TestStorage($root);
$storage->assertActive();
$realSource = $root . DIRECTORY_SEPARATOR . 'document_templates' . DIRECTORY_SEPARATOR . 'summon_letter.docx';
$realShaBefore = hash_file('sha256', $realSource);
if (!copy($realSource, $storage->track($storage->template('summon_letter.docx')))) throw new RuntimeException('Cannot copy summon_letter.docx.');
$complaintsDir = $storage->root . DIRECTORY_SEPARATOR . 'generated_documents' . DIRECTORY_SEPARATOR . 'complaints';

$prefix = 'capstone_bill_';
$scratch = $prefix . getmypid() . '_' . random_int(1000, 9999);
$realDatabase = $DB_NAME;
$passed = 0; $failed = 0;
$admin = null; $scratchDb = null; $server = null;
$sessionPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bill-sessions-' . getmypid();
$prepend = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bill-prepend-' . getmypid() . '.php';
$serverLog = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bill-server-' . getmypid();

function b_section(string $id, string $text): void { echo "\n{$id}) {$text}\n"; }
function b_check(string $name, bool $ok, string $detail = ''): void {
    global $passed, $failed;
    if ($ok) { $passed++; echo "   PASS {$name}\n"; return; }
    $failed++; echo "   FAIL {$name}" . ($detail !== '' ? ' :: ' . mb_substr($detail, 0, 700) : '') . "\n";
}
function b_value(mysqli $db, string $sql): mixed { $r = $db->query($sql); $row = $r ? $r->fetch_row() : null; return $row[0] ?? null; }
function b_rows(mysqli $db, string $sql): array { $r = $db->query($sql); return $r ? $r->fetch_all(MYSQLI_ASSOC) : []; }
function b_multi(mysqli $db, string $sql): void {
    $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql) ?? $sql;
    if (!$db->multi_query($sql)) throw new RuntimeException('SQL failed: ' . $db->error);
    do { if ($r = $db->store_result()) $r->free(); } while ($db->more_results() && $db->next_result());
    if ($db->errno) throw new RuntimeException('SQL failed: ' . $db->error);
}
function b_http(string $url, string $method = 'GET', array|string|null $body = null, ?string $cookie = null, string $type = 'application/json'): array {
    $ch = curl_init($url); $headers = [];
    if ($cookie !== null && $cookie !== '') $headers[] = 'Cookie: ' . $cookie;
    if (is_string($body)) $headers[] = 'Content-Type: ' . $type;
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_POSTFIELDS => $body, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 180]);
    $raw = (string)curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $size = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    $bodyOut = substr($raw, $size);
    $json = json_decode($bodyOut, true);
    return ['status' => $status, 'headers' => substr($raw, 0, $size), 'body' => $bodyOut, 'json' => is_array($json) ? $json : []];
}
function b_stop($process): void {
    if (!is_resource($process)) return;
    $state = proc_get_status($process);
    if ($state['running']) {
        if (PHP_OS_FAMILY === 'Windows') @exec('taskkill /PID ' . (int)$state['pid'] . ' /T /F 2>NUL');
        proc_terminate($process);
    }
    proc_close($process);
}
/** Keys (any depth) of a decoded structure that contain one of $needles. */
function b_key_hits(mixed $value, array $needles, string $path = ''): array {
    $out = [];
    if (!is_array($value)) return $out;
    foreach ($value as $k => $v) {
        foreach ($needles as $n) if (stripos((string)$k, $n) !== false) $out[] = $path . '/' . $k;
        $out = array_merge($out, b_key_hits($v, $needles, $path . '/' . $k));
    }
    return $out;
}

$cleanup = static function () use (&$admin, &$scratchDb, &$server, $scratch, $prefix, $sessionPath, $prepend, $serverLog, $complaintsDir, $storage): void {
    b_stop($server); $server = null;
    if (is_dir($sessionPath)) foreach (glob($sessionPath . DIRECTORY_SEPARATOR . 'sess_*') ?: [] as $file) @unlink($file);
    @rmdir($sessionPath);
    foreach ([$prepend, $serverLog . '.out', $serverLog . '.err'] as $file) @unlink($file);
    if (is_dir($complaintsDir)) {
        foreach (glob($complaintsDir . DIRECTORY_SEPARATOR . '{,.}*', GLOB_BRACE) ?: [] as $file) if (is_file($file)) @unlink($file);
        @rmdir($complaintsDir);
    }
    foreach (glob($storage->templates . DIRECTORY_SEPARATOR . 'tpl_*.docx') ?: [] as $file) $storage->track($file);
    foreach (glob($storage->previews . DIRECTORY_SEPARATOR . '*.pdf') ?: [] as $file) $storage->track($file);
    if ($scratchDb instanceof mysqli) { @$scratchDb->close(); $scratchDb = null; }
    if ($admin instanceof mysqli) {
        if (str_starts_with($scratch, $prefix) && preg_match('/^[A-Za-z0-9_]+$/', $scratch)) $admin->query("DROP DATABASE IF EXISTS `{$scratch}`");
        $admin->close(); $admin = null;
    }
};

$exitCode = 1;
try {
    // ===================================================================== pure helpers
    b_section('0', 'Pure helpers of _billing.php.');
    $amountStatus = static function (mixed $raw): string {
        try { return billing_amount($raw); } catch (ComplaintProblem $p) { return (string)$p->status; }
    };
    b_check('money ok: "50" -> 50.00, "50.5" -> 50.50, "50.50" -> 50.50, "100000" -> 100000.00, "0.01" -> 0.01',
        $amountStatus('50') === '50.00' && $amountStatus('50.5') === '50.50' && $amountStatus('50.50') === '50.50'
        && $amountStatus('100000') === '100000.00' && $amountStatus('0.01') === '0.01');
    $bad = ['-1', '0', '1e2', '50.555', ' 50', '100000.01', '', '50.', '.5', '1,000', 50, 50.5, null, true];
    b_check('money refused (422): -1, 0, 1e2, 50.555, " 50", 100000.01, "", "50.", ".5", "1,000", JSON numbers, null, true',
        array_filter($bad, static fn($v) => $amountStatus($v) !== '422') === [], json_encode(array_map($amountStatus, $bad)));
    $feeStatus = static function (mixed $raw): string {
        try { return billing_fee($raw); } catch (ComplaintProblem $p) { return (string)$p->status; }
    };
    b_check('type fee: "0" -> 0.00 allowed; "-1" and "100000.01" refused', $feeStatus('0') === '0.00' && $feeStatus('-1') === '422' && $feeStatus('100000.01') === '422');
    $longest = billing_sms_body('issued', 'CSR-20261117-AB12CD', BILLING_MAX_CENTS);
    b_check('SMS texts at most 160 characters (billed at PHP 100,000.00), GSM text only (no peso sign)',
        mb_strlen($longest) <= 160 && mb_strlen(billing_sms_body('paid', 'CSR-20261117-AB12CD', 1)) <= 160
        && mb_strlen(billing_sms_body('waived', 'CSR-20261117-AB12CD', 1)) <= 160
        && str_contains($longest, 'PHP 100,000.00') && preg_match('/[^\x20-\x7E]/', $longest) === 0, $longest);

    // ===================================================================== setup
    $admin = new mysqli($DB_HOST, $DB_USER, $DB_PASS);
    if ($admin->connect_errno) throw new RuntimeException('MySQL unavailable.');
    $realCounts = [];
    foreach (['complaints', 'complaint_types', 'complaint_parties', 'complaint_hearings', 'audit_log', 'sms_log', 'notifications'] as $table) {
        $realCounts[$table] = (int)b_value($admin, "SELECT COUNT(*) FROM `{$realDatabase}`.`{$table}`");
    }
    if ((int)b_value($admin, "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='" . $admin->real_escape_string($realDatabase) . "' AND table_name='complaint_bills'") === 1) {
        $realCounts['complaint_bills'] = (int)b_value($admin, "SELECT COUNT(*) FROM `{$realDatabase}`.`complaint_bills`");
    }
    if (!$admin->query("CREATE DATABASE `{$scratch}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) throw new RuntimeException($admin->error);
    $scratchDb = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $scratch);
    $scratchDb->set_charset('utf8mb4');
    b_multi($scratchDb, (string)file_get_contents($root . '/database/database.sql'));
    $DB_NAME = $scratch;
    $db = get_db();

    $password = 'Bill-pass-2026!';
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $official = static function (string $user, string $role, string $fname) use ($scratchDb, $hash): int {
        $stmt = $scratchDb->prepare("INSERT INTO barangay_officials (fname,lname,username,role,address,contact,password,position,status) VALUES (?,'Official',?,?,'Purok 1','',?,'Clerk','active')");
        $stmt->bind_param('ssss', $fname, $user, $role, $hash); $stmt->execute(); $id = (int)$stmt->insert_id; $stmt->close();
        return $id;
    };
    $staffId = $official('bill-staff', 'staff', 'Stella');
    $adminId = $official('bill-admin', 'admin', 'Adam');
    $householdId = (int)$scratchDb->query("INSERT INTO households (household_code,address,household_size) VALUES ('BILL-" . getmypid() . "','Purok 2',3)") ? (int)$scratchDb->insert_id : 0;
    $resident = static function (string $f, string $l, string $user, string $contact) use ($scratchDb, $hash, $householdId): int {
        $stmt = $scratchDb->prepare("INSERT INTO residents (fname,lname,username,address,contact,password,status,household_id) VALUES (?,?,?,'Purok 2',?,?,'active',?)");
        $stmt->bind_param('sssssi', $f, $l, $user, $contact, $hash, $householdId); $stmt->execute(); $id = (int)$stmt->insert_id; $stmt->close();
        return $id;
    };
    $rosaId = $resident('Rosa', 'Filer', 'bill-rosa', '+639171110081');
    $benId = $resident('Ben', 'Neighbour', 'bill-ben', '+639171110082');
    $carlaId = $resident('Carla', 'Other', 'bill-carla', '+639171110083');
    $scratchDb->query("UPDATE households SET head_resident_id={$rosaId} WHERE id={$householdId}");

    $proc = proc_open([PHP_BINARY, $root . '/project-support/scripts/build-summons-template.php', '--database=' . $scratch, '--apply'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $buildOut = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $buildCode = proc_close($proc);
    b_check('setup: scratch database from database.sql (complaint_bills + default_fee present); Summons v1 active in temp storage',
        $buildCode === 0 && str_contains($buildOut, 'activated Summons v1') && complaint_billing_ready($db)
        && (int)b_value($scratchDb, 'SELECT COUNT(*) FROM complaint_types WHERE default_fee <> 0') === 0, $buildOut);

    mkdir($sessionPath, 0700, true);
    file_put_contents($prepend, "<?php foreach (['GEMINI_API_KEY', 'GEMINI_MODEL', 'HTTPSMS_API_KEY', 'HTTPSMS_FROM_NUMBER'] as \$k) { putenv(\$k . '='); \$_ENV[\$k] = ''; \$_SERVER[\$k] = ''; }\n"
        . "if (!str_starts_with((string)getenv('BARANGAY_DB_NAME'), 'capstone_bill_')) { http_response_code(500); exit; }\n"
        . 'require_once ' . var_export($root . '/db.php', true) . "; \$DB_NAME = getenv('BARANGAY_DB_NAME');");
    $base = null;
    for ($attempt = 0; $attempt < 10 && $base === null; $attempt++) {
        $port = random_int(20000, 45000);
        $environment = ['BARANGAY_DB_NAME' => $scratch, 'BARANGAY_STORAGE_ROOT' => $storage->root,
            'SystemRoot' => getenv('SystemRoot') ?: 'C:\\Windows', 'PATH' => getenv('PATH') ?: '', 'TEMP' => sys_get_temp_dir(), 'TMP' => sys_get_temp_dir()];
        $server = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $sessionPath, '-d', 'auto_prepend_file=' . $prepend, '-S', '127.0.0.1:' . $port, '-t', $root],
            [0 => ['pipe', 'r'], 1 => ['file', $serverLog . '.out', 'a'], 2 => ['file', $serverLog . '.err', 'a']], $serverPipes, $root, $environment);
        if (!is_resource($server)) continue;
        for ($i = 0; $i < 40; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
            if (is_resource($socket)) { fclose($socket); $base = 'http://127.0.0.1:' . $port; break; }
            usleep(25000);
        }
        if ($base === null) { b_stop($server); $server = null; }
    }
    if ($base === null) throw new RuntimeException('Endpoint server did not start.');
    $login = static function (string $action, string $username) use ($base, $password): string {
        $r = b_http($base . '/pages/login.php', 'POST', ['action' => $action, 'username' => $username, 'password' => $password]);
        preg_match('/^Set-Cookie:\s*([^;\r\n]+)/mi', $r['headers'], $m);
        return $r['status'] === 302 ? (string)($m[1] ?? '') : '';
    };
    $staff = $login('official-login', 'bill-staff');
    $adminC = $login('official-login', 'bill-admin');
    $rosa = $login('resident-login', 'bill-rosa');
    $carla = $login('resident-login', 'bill-carla');
    b_check('setup: staff, admin, Rosa and Carla logins', $staff !== '' && $adminC !== '' && $rosa !== '' && $carla !== '');
    $post = static fn(string $path, array $body, ?string $cookie): array => b_http($base . '/api/' . $path, 'POST', (string)json_encode($body), $cookie);
    $get = static fn(string $path, ?string $cookie): array => b_http($base . '/api/' . $path, 'GET', null, $cookie);
    $listFor = static function (?string $cookie, int $id) use ($get): ?array {
        foreach (($get('complaints/list.php', $cookie)['json']['complaints'] ?? []) as $row) if ((int)$row['id'] === $id) return $row;
        return null;
    };
    $file = static function (int $typeId, string $what, array $extra = []) use ($post, $rosa): int {
        $body = ['complaint_type_id' => $typeId, 'location_text' => 'Purok 2 lot line', 'description' => "Billing test complaint: {$what}.",
            'severity' => 'medium', 'respondents' => [['full_name' => 'BILL Pedro Respondent', 'address' => null, 'contact_no' => null]]];
        if ($extra !== []) $body['extra_fields'] = $extra;
        $r = $post('complaints/create.php', $body, $rosa);
        if ((int)($r['json']['id'] ?? 0) <= 0) echo '   (filing failed: ' . $r['body'] . ")
";
        return (int)($r['json']['id'] ?? 0);
    };
    $billGet = static fn(int $cid) => $get('complaints/bill_get.php?complaint_id=' . $cid, $staff)['json'];
    $tz = new DateTimeZone('Asia/Manila');
    $day = (new DateTimeImmutable('now', $tz))->modify('+7 days');
    if ($day->format('N') === '7') $day = $day->modify('+1 day');
    $slot = 7;
    $schedule = static function (int $cid) use ($post, $staff, $staffId, $day, &$slot): array {
        $slot++;
        return $post('hearings/schedule.php', ['complaint_id' => $cid, 'date' => $day->format('Y-m-d'), 'start_time' => sprintf('%02d:00', $slot), 'duration_minutes' => 60,
            'venue' => 'Barangay Hall', 'presiding_official_id' => $staffId, 'request_key' => bin2hex(random_bytes(16))], $staff);
    };
    $smsMark = static fn(): int => (int)b_value($scratchDb, 'SELECT COALESCE(MAX(id),0) FROM sms_log');
    $noticeMark = static fn(): int => (int)b_value($scratchDb, 'SELECT COALESCE(MAX(id),0) FROM notifications');
    $smsSince = static fn(int $mark, string $endpoint): array => array_map('intval', array_column(b_rows($scratchDb,
        "SELECT resident_id FROM sms_log WHERE id > {$mark} AND endpoint='" . $scratchDb->real_escape_string($endpoint) . "' ORDER BY resident_id"), 'resident_id'));

    // ===================================================================== a
    b_section('a', 'Type fee 50.00 -> bill prefilled -> resident view; SMS + notice for registered complainants only.');
    $made = $post('complaint_types/save_type.php', ['type_name' => 'Barangay Clearance Dispute', 'respondent_mode' => 'optional', 'default_fee' => '50.00'], $staff);
    $feeType = (int)($made['json']['type']['id'] ?? 0);
    $typeList = $get('complaint_types/list.php', $staff)['json']['types'] ?? [];
    $listed = array_values(array_filter($typeList, static fn($t) => (int)$t['id'] === $feeType))[0] ?? [];
    b_check('type created with default_fee "50.00"; complaint_types/list.php returns it; seeded types stay "0.00"',
        $made['status'] === 200 && ($made['json']['type']['default_fee'] ?? '') === '50.00' && ($listed['default_fee'] ?? '') === '50.00'
        && array_filter($typeList, static fn($t) => (int)$t['id'] !== $feeType && $t['default_fee'] !== '0.00') === [], $made['body']);
    $c1 = $file($feeType, 'C1 paid');
    $ref1 = (string)b_value($scratchDb, "SELECT reference_no FROM complaints WHERE id={$c1}");
    $g = $billGet($c1);
    $off = $listFor($staff, $c1);
    b_check('before billing: bill_get default_fee 50.00, not clear, gate blocker, can_issue; official list: bill null, type_default_fee 50.00',
        $c1 > 0 && ($g['default_fee'] ?? '') === '50.00' && ($g['clear'] ?? null) === false && ($g['blocker'] ?? '') === BILLING_MSG_GATE
        && ($g['can_issue'] ?? null) === true && array_key_exists('bill', $g) && $g['bill'] === null
        && $off !== null && array_key_exists('bill', $off) && $off['bill'] === null && ($off['type_default_fee'] ?? '') === '50.00', json_encode([$g, $off]));
    $sm = $smsMark(); $nm = $noticeMark();
    $issue = $post('complaints/bill_issue.php', ['complaint_id' => $c1, 'amount' => $g['default_fee'], 'billing_note' => 'Filing fee'], $staff);
    b_check('issue with the prefilled 50.00 -> 200, unpaid, version 1, not clear',
        $issue['status'] === 200 && ($issue['json']['bill']['amount'] ?? '') === '50.00' && ($issue['json']['bill']['status'] ?? '') === 'unpaid'
        && ($issue['json']['bill']['version'] ?? 0) === 1 && ($issue['json']['clear'] ?? null) === false
        && ($issue['json']['bill']['issued_by_name'] ?? '') === 'Stella Official', $issue['body']);
    $rosaRow = $listFor($rosa, $c1);
    $rosaBody = $get('complaints/list.php', $rosa)['body'];
    $billKeys = array_keys($rosaRow['bill'] ?? []); sort($billKeys);
    b_check('Rosa (filer) sees exactly amount, billing_note, issued_at, settled_at, status; no receipt, reason, official or type fee',
        $billKeys === ['amount', 'billing_note', 'issued_at', 'settled_at', 'status'] && ($rosaRow['bill']['amount'] ?? '') === '50.00'
        && ($rosaRow['bill']['billing_note'] ?? '') === 'Filing fee' && !array_key_exists('type_default_fee', $rosaRow ?? [])
        && !str_contains($rosaBody, 'waive_reason') && !str_contains($rosaBody, 'receipt_no') && !str_contains($rosaBody, 'issued_by'), json_encode($rosaRow['bill'] ?? null));
    $offBill = $listFor($staff, $c1)['bill'] ?? [];
    $offKeys = array_keys($offBill); sort($offKeys);
    b_check('official list bill keys: amount, billing_note, issued_at, receipt_no, settled_at, status, waive_reason (no ids)',
        $offKeys === ['amount', 'billing_note', 'issued_at', 'receipt_no', 'settled_at', 'status', 'waive_reason'], json_encode($offBill));
    b_check('Carla does not see C1', $listFor($carla, $c1) === null);
    $notices = b_rows($scratchDb, "SELECT resident_id, title, body FROM notifications WHERE id > {$nm}");
    b_check('SMS (sms_log complaint_bill_issued) and in-app notice to Rosa only, with reference and amount',
        $smsSince($sm, 'complaint_bill_issued') === [$rosaId] && count($notices) === 1 && (int)$notices[0]['resident_id'] === $rosaId
        && $notices[0]['title'] === 'Complaint Fee' && $notices[0]['body'] === billing_notice_body('issued', $ref1, 5000)
        && str_contains($notices[0]['body'], 'PHP 50.00') && str_contains($notices[0]['body'], $ref1), json_encode($notices));
    $timeline = (string)b_value($scratchDb, "SELECT note FROM complaint_updates WHERE complaint_id={$c1} ORDER BY id DESC LIMIT 1");
    b_check('timeline note "Fee issued." (no amount)', $timeline === 'Fee issued.', $timeline);
    $walk = $post('complaints/create_official.php', ['complaint_type_id' => $feeType, 'location_text' => 'Purok 2 drainage', 'description' => 'Walk-in billing test complaint.',
        'severity' => 'low', 'complainants' => [['full_name' => 'BILL Walkin Complainant', 'address' => null, 'contact_no' => '0917 000 3333']],
        'respondents' => [['resident_id' => $benId]]], $staff);
    $w = (int)($walk['json']['id'] ?? 0);
    $sm = $smsMark(); $nm = $noticeMark();
    $wIssue = $post('complaints/bill_issue.php', ['complaint_id' => $w, 'amount' => '50'], $staff);
    b_check('walk-in (unregistered complainant, registered respondent Ben): bill issued; no SMS and no notice to anyone (respondents never billed)',
        $walk['status'] === 200 && $wIssue['status'] === 200 && array_key_exists('billing_note', $wIssue['json']['bill'] ?? []) && $wIssue['json']['bill']['billing_note'] === null
        && $smsSince($sm, 'complaint_bill_issued') === [] && (int)b_value($scratchDb, "SELECT COUNT(*) FROM notifications WHERE id > {$nm}") === 0, $walk['body'] . $wIssue['body']);

    // ===================================================================== b
    b_section('b', 'Unpaid: scheduling refused; check.php and for_complaint show the reason.');
    $s = $schedule($c1);
    b_check('schedule while unpaid -> 409 gate message; no session, no case number',
        $s['status'] === 409 && ($s['json']['message'] ?? '') === BILLING_MSG_GATE
        && (int)b_value($scratchDb, "SELECT COUNT(*) FROM complaint_hearings WHERE complaint_id={$c1}") === 0
        && b_value($scratchDb, "SELECT case_no FROM complaints WHERE id={$c1}") === null, $s['body']);
    $chk = $get('hearings/check.php?complaint_id=' . $c1 . '&date=' . $day->format('Y-m-d') . '&start_time=10:00&duration_minutes=60&venue=Barangay%20Hall&presiding_official_id=' . $staffId, $staff);
    $fc = $get('hearings/for_complaint.php?complaint_id=' . $c1, $staff);
    b_check('check.php lists the gate message; for_complaint can_schedule false with the same blocker',
        $chk['status'] === 200 && ($chk['json']['ok'] ?? null) === false && in_array(BILLING_MSG_GATE, array_column($chk['json']['problems'] ?? [], 'message'), true)
        && ($fc['json']['complaint']['can_schedule'] ?? null) === false && ($fc['json']['complaint']['schedule_blocker'] ?? '') === BILLING_MSG_GATE, $chk['body'] . $fc['body']);

    // ===================================================================== g (+ summons part of b)
    b_section('g', 'Type fee 0: no bill -> clear; a bill on a 0-fee type must be settled; summons gate.');
    $zeroType = (int)b_value($scratchDb, "SELECT id FROM complaint_types WHERE type_code='other'");
    $c0 = $file($zeroType, 'C0 zero-fee', ['specify' => 'Billing test']);
    b_check('0-fee type, no bill: bill_get clear, no blocker; list badge data type_default_fee 0.00',
        ($billGet($c0)['clear'] ?? null) === true && array_key_exists('blocker', $billGet($c0)) && $billGet($c0)['blocker'] === null && ($listFor($staff, $c0)['type_default_fee'] ?? '') === '0.00');
    $s0 = $schedule($c0);
    $h0 = (int)($s0['json']['hearing']['id'] ?? 0);
    b_check('existing flow unaffected: schedule a 0-fee complaint without a bill -> 200', $s0['status'] === 200 && $h0 > 0, $s0['body']);
    $i0 = $post('complaints/bill_issue.php', ['complaint_id' => $c0, 'amount' => '25.00'], $staff);
    $resp0 = (int)b_value($scratchDb, "SELECT id FROM complaint_parties WHERE complaint_id={$c0} AND role='respondent' LIMIT 1");
    $sum = $post('hearings/summons_generate.php', ['hearing_id' => $h0, 'party_id' => $resp0], $staff);
    $sl = $get('hearings/summons_list.php?hearing_id=' . $h0, $staff);
    $h0v = (int)b_value($scratchDb, "SELECT version FROM complaint_hearings WHERE id={$h0}");
    $re0 = $post('hearings/reschedule.php', ['hearing_id' => $h0, 'version' => $h0v, 'date' => $day->format('Y-m-d'), 'start_time' => '17:00', 'duration_minutes' => 60,
        'venue' => 'Barangay Hall', 'presiding_official_id' => $staffId, 'reason' => 'Billing test reschedule', 'request_key' => bin2hex(random_bytes(16))], $staff);
    b_check('bill issued on a 0-fee type: not clear; summons generate -> 409 gate; summons_list blocker; reschedule -> 409 gate',
        $i0['status'] === 200 && ($i0['json']['clear'] ?? null) === false
        && $sum['status'] === 409 && ($sum['json']['message'] ?? '') === BILLING_MSG_GATE
        && ($sl['json']['blocker'] ?? '') === BILLING_MSG_GATE && ($sl['json']['respondents'][0]['can_generate'] ?? null) === false
        && $re0['status'] === 409 && ($re0['json']['message'] ?? '') === BILLING_MSG_GATE
        && (int)b_value($scratchDb, "SELECT COUNT(*) FROM complaint_documents WHERE hearing_id={$h0}") === 0, $sum['body'] . $re0['body']);
    $p0 = $post('complaints/bill_mark_paid.php', ['complaint_id' => $c0, 'version' => 1, 'receipt_no' => 'OR-0001'], $staff);
    $sum2 = $post('hearings/summons_generate.php', ['hearing_id' => $h0, 'party_id' => $resp0], $staff);
    b_check('after mark paid: summons generated (200)', $p0['status'] === 200 && $sum2['status'] === 200 && (int)($sum2['json']['document']['id'] ?? 0) > 0, $p0['body'] . $sum2['body']);
    // A fee set later on the type blocks that type's existing open complaints.
    $c7 = $file($zeroType, 'C7 fee set later', ['specify' => 'Billing test']);
    $otherRow = b_rows($scratchDb, "SELECT version FROM complaint_types WHERE id={$zeroType}")[0];
    $setFee = $post('complaint_types/save_type.php', ['id' => $zeroType, 'version' => (int)$otherRow['version'], 'type_name' => 'Other', 'default_fee' => '20'], $staff);
    $s7 = $schedule($c7);
    $back = $post('complaint_types/save_type.php', ['id' => $zeroType, 'version' => (int)($setFee['json']['type']['version'] ?? 0), 'type_name' => 'Other', 'default_fee' => '0'], $staff);
    $s7b = $schedule($c7);
    b_check('fee set on the type later: its existing open complaint cannot be scheduled (409); fee back to 0 -> 200; audit has the fee change',
        $setFee['status'] === 200 && ($setFee['json']['type']['default_fee'] ?? '') === '20.00' && $s7['status'] === 409
        && $back['status'] === 200 && ($back['json']['type']['default_fee'] ?? '') === '0.00' && $s7b['status'] === 200
        && str_contains((string)b_value($scratchDb, "SELECT details FROM audit_log WHERE action='Updated complaint type' ORDER BY id DESC LIMIT 1"), 'default_fee'),
        $setFee['body'] . $s7['body'] . $back['body'] . $s7b['body']);

    // ===================================================================== c
    b_section('c', 'Mark paid (with and without receipt) -> schedule works; paid is final.');
    $sm = $smsMark(); $nm = $noticeMark();
    $paid = $post('complaints/bill_mark_paid.php', ['complaint_id' => $c1, 'version' => 1, 'receipt_no' => 'OR-1234/A'], $staff);
    b_check('C1 marked paid with receipt OR-1234/A: settled_by/at set, clear; SMS + notice to Rosa',
        $paid['status'] === 200 && ($paid['json']['bill']['status'] ?? '') === 'paid' && ($paid['json']['bill']['receipt_no'] ?? '') === 'OR-1234/A'
        && ($paid['json']['clear'] ?? null) === true && ($paid['json']['bill']['settled_by_name'] ?? '') === 'Stella Official'
        && b_value($scratchDb, "SELECT settled_at FROM complaint_bills WHERE complaint_id={$c1}") !== null
        && $smsSince($sm, 'complaint_bill_paid') === [$rosaId]
        && b_value($scratchDb, "SELECT body FROM notifications WHERE id > {$nm} AND resident_id={$rosaId}") === billing_notice_body('paid', $ref1, 0), $paid['body']);
    $s1 = $schedule($c1);
    b_check('schedule C1 after payment -> 200 (case number assigned)', $s1['status'] === 200 && b_value($scratchDb, "SELECT case_no FROM complaints WHERE id={$c1}") !== null, $s1['body']);
    $wPaid = $post('complaints/bill_mark_paid.php', ['complaint_id' => $w, 'version' => 1], $staff);
    $wPaid2 = $post('complaints/bill_mark_paid.php', ['complaint_id' => $w, 'version' => 1, 'receipt_no' => '   '], $staff);
    b_check('walk-in marked paid without a receipt -> 200, receipt null; paid again -> 409 final',
        $wPaid['status'] === 200 && array_key_exists('receipt_no', $wPaid['json']['bill'] ?? []) && $wPaid['json']['bill']['receipt_no'] === null && $wPaid2['status'] === 409, $wPaid['body'] . $wPaid2['body']);
    $u = $post('complaints/bill_update.php', ['complaint_id' => $c1, 'version' => 2, 'amount' => '60'], $staff);
    $wv = $post('complaints/bill_waive.php', ['complaint_id' => $c1, 'version' => 2, 'reason' => 'Changed mind'], $staff);
    b_check('paid -> update 409 and waive 409 (final)', $u['status'] === 409 && ($u['json']['message'] ?? '') === BILLING_MSG_FINAL
        && $wv['status'] === 409 && ($wv['json']['message'] ?? '') === BILLING_MSG_FINAL, $u['body'] . $wv['body']);
    $badReceipt = $post('complaints/bill_mark_paid.php', ['complaint_id' => $c0, 'version' => 2, 'receipt_no' => 'OR<script>'], $staff);
    b_check('receipt with characters outside [A-Za-z0-9-/ ] -> 422', $badReceipt['status'] === 422, $badReceipt['body']);

    // ===================================================================== d
    b_section('d', 'Waive: reason required; staff and admin may waive; the resident never sees the reason.');
    $c2 = $file($feeType, 'C2 staff waive');
    $c3 = $file($feeType, 'C3 admin waive');
    $post('complaints/bill_issue.php', ['complaint_id' => $c2, 'amount' => '50.00'], $staff);
    $post('complaints/bill_issue.php', ['complaint_id' => $c3, 'amount' => '75.25'], $staff);
    $noReason = $post('complaints/bill_waive.php', ['complaint_id' => $c2, 'version' => 1], $staff);
    $short = $post('complaints/bill_waive.php', ['complaint_id' => $c2, 'version' => 1, 'reason' => 'abc'], $staff);
    b_check('waive without a reason -> 422; reason under 5 characters -> 422', $noReason['status'] === 422 && $short['status'] === 422, $noReason['body'] . $short['body']);
    $sm = $smsMark();
    $secretReason = 'BILL-SECRET-REASON indigent family';
    $ws = $post('complaints/bill_waive.php', ['complaint_id' => $c2, 'version' => 1, 'reason' => $secretReason], $staff);
    $wa = $post('complaints/bill_waive.php', ['complaint_id' => $c3, 'version' => 1, 'reason' => 'Senior citizen exemption'], $adminC);
    b_check('staff waive -> 200; admin waive -> 200 (settled_by admin); SMS complaint_bill_waived to Rosa twice',
        $ws['status'] === 200 && ($ws['json']['bill']['status'] ?? '') === 'waived' && ($ws['json']['bill']['waive_reason'] ?? '') === $secretReason
        && $wa['status'] === 200 && (int)b_value($scratchDb, "SELECT settled_by FROM complaint_bills WHERE complaint_id={$c3}") === $adminId
        && $smsSince($sm, 'complaint_bill_waived') === [$rosaId, $rosaId], $ws['body'] . $wa['body']);
    $rosaList = $get('complaints/list.php', $rosa);
    $rosaC2 = null; foreach ($rosaList['json']['complaints'] ?? [] as $row) if ((int)$row['id'] === $c2) $rosaC2 = $row;
    $timelineC2 = $get('complaints/timeline.php?id=' . $c2, $rosa)['body'];
    b_check('Rosa sees "waived" and never the reason (list, timeline, notices)',
        ($rosaC2['bill']['status'] ?? '') === 'waived' && !str_contains($rosaList['body'], 'BILL-SECRET-REASON')
        && !str_contains($timelineC2, 'BILL-SECRET-REASON')
        && (int)b_value($scratchDb, "SELECT COUNT(*) FROM notifications WHERE body LIKE '%BILL-SECRET%'") === 0
        && (int)b_value($scratchDb, "SELECT COUNT(*) FROM complaint_updates WHERE note LIKE '%BILL-SECRET%' OR note LIKE '%50.00%'") === 0, json_encode($rosaC2['bill'] ?? null));
    $sc2 = $schedule($c2);
    b_check('waived -> schedule 200', $sc2['status'] === 200, $sc2['body']);

    // ===================================================================== e
    b_section('e', 'Money rule over HTTP.');
    $c4 = $file($feeType, 'C4 money');
    $e1 = $post('complaints/bill_issue.php', ['complaint_id' => $c4, 'amount' => '50'], $staff);
    $e2 = $post('complaints/bill_update.php', ['complaint_id' => $c4, 'version' => 1, 'amount' => '50.5'], $staff);
    $e3 = $post('complaints/bill_update.php', ['complaint_id' => $c4, 'version' => 2, 'amount' => '50.50'], $staff);
    b_check('"50" -> 50.00 (issue); "50.5" -> 50.50 (update, version 2); "50.50" -> no change (version stays 2)',
        ($e1['json']['bill']['amount'] ?? '') === '50.00' && ($e2['json']['bill']['amount'] ?? '') === '50.50' && ($e2['json']['changed'] ?? null) === true
        && $e3['status'] === 200 && ($e3['json']['changed'] ?? null) === false && ($e3['json']['bill']['version'] ?? 0) === 2, $e1['body'] . $e2['body'] . $e3['body']);
    $refused = [];
    foreach (['-1', '0', '1e2', '50.555', ' 50', '100000.01', 50] as $amount) {
        $r = $post('complaints/bill_update.php', ['complaint_id' => $c4, 'version' => 2, 'amount' => $amount], $staff);
        if ($r['status'] !== 422 || ($r['json']['message'] ?? '') !== BILLING_MSG_AMOUNT) $refused[] = json_encode($amount) . '=' . $r['status'];
    }
    b_check("'-1', '0', '1e2', '50.555', ' 50', '100000.01' and a JSON number -> 422", $refused === [], implode(', ', $refused));
    $typeBad = $post('complaint_types/save_type.php', ['type_name' => 'Bad Fee Type', 'default_fee' => '-1'], $staff);
    $typeNum = $post('complaint_types/save_type.php', ['type_name' => 'Bad Fee Type', 'default_fee' => 5], $staff);
    b_check('type fee "-1" and a JSON number -> 422', $typeBad['status'] === 422 && $typeNum['status'] === 422, $typeBad['body'] . $typeNum['body']);
    $note = $post('complaints/bill_update.php', ['complaint_id' => $c4, 'version' => 2, 'amount' => '50.50', 'billing_note' => str_repeat('n', 256)], $staff);
    b_check('billing note over 255 characters -> 422', $note['status'] === 422, $note['body']);

    // ===================================================================== f
    b_section('f', 'Stale version, resolved complaint, second bill.');
    $stale = $post('complaints/bill_update.php', ['complaint_id' => $c4, 'version' => 1, 'amount' => '51'], $staff);
    $staleP = $post('complaints/bill_mark_paid.php', ['complaint_id' => $c4, 'version' => 1], $staff);
    b_check('stale version -> 409 (update and mark paid)', $stale['status'] === 409 && ($stale['json']['message'] ?? '') === COMPLAINT_PARTY_MSG_STALE
        && $staleP['status'] === 409, $stale['body'] . $staleP['body']);
    $again = $post('complaints/bill_issue.php', ['complaint_id' => $c4, 'amount' => '10'], $staff);
    b_check('second issue -> 409; still one bill', $again['status'] === 409 && ($again['json']['message'] ?? '') === BILLING_MSG_EXISTS
        && (int)b_value($scratchDb, "SELECT COUNT(*) FROM complaint_bills WHERE complaint_id={$c4}") === 1, $again['body']);
    $c5 = $file($feeType, 'C5 resolved');
    $res = $post('complaints/notify_resolved.php', ['id' => $c5, 'official_note' => 'Resolved without a fee.'], $staff);
    $i5 = $post('complaints/bill_issue.php', ['complaint_id' => $c5, 'amount' => '50'], $staff);
    b_check('resolved complaint: issue -> 409', $res['status'] === 200 && $i5['status'] === 409 && ($i5['json']['message'] ?? '') === BILLING_MSG_ISSUE_STATUS, $res['body'] . $i5['body']);
    $none = $post('complaints/bill_mark_paid.php', ['complaint_id' => $c5, 'version' => 1], $staff);
    $unknown = $get('complaints/bill_get.php?complaint_id=99999999', $staff);
    b_check('mark paid with no bill -> 404; bill_get unknown complaint -> 404', $none['status'] === 404 && $unknown['status'] === 404, $none['body'] . $unknown['body']);
    $unknownKey = $post('complaints/bill_issue.php', ['complaint_id' => $c5, 'amount' => '5', 'status' => 'paid'], $staff);
    b_check('unknown body key -> 422', $unknownKey['status'] === 422, $unknownKey['body']);

    // ===================================================================== h
    b_section('h', 'Guards.');
    $guards = [
        ['complaints/bill_get.php?complaint_id=' . $c1, 'GET', false],
        ['complaints/bill_issue.php', 'POST', true],
        ['complaints/bill_update.php', 'POST', true],
        ['complaints/bill_mark_paid.php', 'POST', true],
        ['complaints/bill_waive.php', 'POST', true],
    ];
    $table = [];
    foreach ($guards as [$path, $method, $strict]) {
        $url = $base . '/api/' . $path;
        $out = b_http($url, $method, $method === 'POST' ? '{}' : null, null)['status'];
        $role = b_http($url, $method, $method === 'POST' ? '{}' : null, $carla)['status'];
        $other = $method === 'POST' ? 'GET' : 'POST';
        $wrong = b_http($url, $other, $other === 'POST' ? '{}' : null, $staff)['status'];
        $plain = $list = $asText = null;
        if ($strict) {
            $plain = b_http($url, 'POST', 'x=1', $staff, 'text/plain')['status'];
            $asText = b_http($url, 'POST', '{"complaint_id":' . $c1 . '}', $staff, 'text/plain')['status'];
            $list = b_http($url, 'POST', '[1,2]', $staff)['status'];
        }
        $table[] = sprintf('%-34s %-4s out=%d role=%d method=%d%s', strtok($path, '?'), $method, $out, $role, $wrong,
            $strict ? sprintf(' text/plain=%d json-as-text=%d list=%d', $plain, $asText, $list) : '');
        b_check('guard ' . strtok($path, '?') . ': logged out 401, resident 401/403, wrong method 405' . ($strict ? ', non-JSON 415, JSON list 422' : ''),
            $out === 401 && in_array($role, [401, 403], true) && $wrong === 405 && (!$strict || ($plain === 415 && $asText === 415 && $list === 422)), end($table));
    }
    echo "   --- guard table ---\n   " . implode("\n   ", $table) . "\n";

    // ===================================================================== i
    b_section('i', 'Report billing totals; AI payload has no billing.');
    $report = $get('stats/complaints_report.php', $staff);
    $billing = $report['json']['billing'] ?? null;
    // Bills: C1 50.00 paid, W 50.00 paid, C0 25.00 paid, C2 50.00 waived, C3 75.25 waived, C4 50.50 unpaid.
    $expected = ['bills_issued' => 6, 'unpaid_count' => 1, 'unpaid_total' => '50.50', 'paid_count' => 3, 'paid_total' => '125.00', 'waived_count' => 2];
    b_check('report billing exact: ' . json_encode($expected), $report['status'] === 200 && $billing === $expected, json_encode($billing));
    $payload = complaint_ai_payload(complaint_report_data($db));
    $aiBillingKeys = array_keys($payload['billing'] ?? []);
    $outside = $payload; unset($outside['billing']);
    $aiText = (string)json_encode($payload);
    b_check('AI payload billing = exactly the six aggregate keys with the report values; no other billing keys anywhere',
        $aiBillingKeys === ['bills_issued', 'unpaid_count', 'unpaid_total', 'paid_count', 'paid_total', 'waived_count']
        && $payload['billing'] === $expected && b_key_hits($outside, ['bill', 'fee', 'paid', 'waive', 'amount', 'receipt']) === [],
        json_encode($payload['billing'] ?? null));
    $fixtureRefs = array_column(b_rows($scratchDb, 'SELECT reference_no FROM complaints'), 'reference_no');
    $aiLeaks = array_values(array_filter(array_merge(['OR-1234/A', 'OR-0001', 'BILL-SECRET', 'Senior citizen', 'Filing fee', 'Rosa', 'Stella', 'Adam',
        'BILL Pedro', 'BILL Walkin', 'Billing test'], $fixtureRefs), static fn(string $n): bool => str_contains($aiText, $n)));
    b_check('AI payload has no fixture names, receipts, waive reasons, notes or reference numbers', $aiLeaks === [], implode(', ', $aiLeaks));
    $audits = b_rows($scratchDb, "SELECT action, details FROM audit_log WHERE target_type='complaint_bill' ORDER BY id");
    b_check('audit rows for issue / update / paid / waive (amount, receipt and reason in officials-only audit)',
        count(array_filter($audits, static fn($a) => $a['action'] === 'Issued complaint fee')) === 6
        && count(array_filter($audits, static fn($a) => $a['action'] === 'Updated complaint fee')) === 1
        && count(array_filter($audits, static fn($a) => $a['action'] === 'Marked complaint fee paid')) === 3
        && count(array_filter($audits, static fn($a) => $a['action'] === 'Waived complaint fee')) === 2
        && count(array_filter($audits, static fn($a) => str_contains($a['details'], 'OR-1234/A'))) === 1, json_encode(array_column($audits, 'action')));
    $inv = (int)b_value($scratchDb, "SELECT COUNT(*) FROM complaint_bills WHERE (status <> 'unpaid' AND (settled_by IS NULL OR settled_at IS NULL))
        OR (status = 'unpaid' AND (settled_by IS NOT NULL OR settled_at IS NOT NULL)) OR (status = 'waived' AND waive_reason IS NULL) OR amount <= 0");
    b_check('scratch invariants: settled rows have settled_by/at, waived rows a reason, amount > 0', $inv === 0);

    // ===================================================================== end
    $after = [];
    foreach (array_keys($realCounts) as $t) $after[$t] = (int)b_value($admin, "SELECT COUNT(*) FROM `{$realDatabase}`.`{$t}`");
    echo "\nEnd.\n";
    b_check('real database row counts unchanged', $after === $realCounts, json_encode([$realCounts, $after]));
    b_check('real summon_letter.docx unchanged', hash_file('sha256', $realSource) === $realShaBefore);
    $exitCode = $failed === 0 ? 0 : 1;
} catch (Throwable $e) {
    b_check('suite ran without an unexpected error', false, get_class($e) . ': ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
    $exitCode = 1;
} finally {
    $cleanup();
}
$total = $passed + $failed;
echo "{$passed}/{$total} passed\n";
exit($exitCode);
