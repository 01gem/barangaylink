<?php
/**
 * BarangayLink - Complaint revision end-to-end suite (Phase 9).
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * Thirteen scenarios (13: Phase 10 fee variant) over HTTP (php -S on a scratch database built from
 * database/database.sql), then a guard sweep of every endpoint added or
 * changed in P2-P8. Templates and generated summons live in TestStorage
 * (%TEMP%/capstone_tpl_<pid>), never in document_templates/ or
 * generated_documents/. No AI, no SMS provider (keys blanked). Everything it
 * creates is dropped with the scratch database / temp storage.
 * Needs LibreOffice (summons generation).
 *
 *   php project-support/tests/complaint-e2e/run.php
 */

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
foreach (['GEMINI_API_KEY', 'GEMINI_MODEL', 'HTTPSMS_API_KEY', 'HTTPSMS_FROM_NUMBER'] as $blocked) {
    putenv($blocked . '='); $_ENV[$blocked] = ''; $_SERVER[$blocked] = '';
}
$root = dirname(__DIR__, 3);
require_once $root . '/api/common.php';
require_once $root . '/api/hearings/_summons.php';
require_once $root . '/api/stats/_complaint_report.php';
require_once $root . '/project-support/tests/_storage.php';
mysqli_report(MYSQLI_REPORT_OFF);

$storage = new TestStorage($root);
$storage->assertActive();
$realSource = $root . DIRECTORY_SEPARATOR . 'document_templates' . DIRECTORY_SEPARATOR . 'summon_letter.docx';
$realShaBefore = hash_file('sha256', $realSource);
if (!copy($realSource, $storage->track($storage->template('summon_letter.docx')))) throw new RuntimeException('Cannot copy summon_letter.docx.');
$complaintsDir = $storage->root . DIRECTORY_SEPARATOR . 'generated_documents' . DIRECTORY_SEPARATOR . 'complaints';

$prefix = 'capstone_e2e_';
$scratch = $prefix . getmypid() . '_' . random_int(1000, 9999);
$realDatabase = $DB_NAME;
$passed = 0; $failed = 0;
$admin = null; $scratchDb = null; $server = null;
$sessionPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'e2e-sessions-' . getmypid();
$prepend = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'e2e-prepend-' . getmypid() . '.php';
$serverLog = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'e2e-server-' . getmypid();
$findings = [];

function e2e_step(int $n, string $text): void { echo "\n{$n}. {$text}\n"; }
function e2e_check(string $name, bool $ok, string $detail = ''): void {
    global $passed, $failed;
    if ($ok) { $passed++; echo "   PASS {$name}\n"; return; }
    $failed++; echo "   FAIL {$name}" . ($detail !== '' ? ' :: ' . mb_substr($detail, 0, 600) : '') . "\n";
}
function e2e_value(mysqli $db, string $sql): mixed { $r = $db->query($sql); $row = $r ? $r->fetch_row() : null; return $row[0] ?? null; }
function e2e_rows(mysqli $db, string $sql): array { $r = $db->query($sql); return $r ? $r->fetch_all(MYSQLI_ASSOC) : []; }
function e2e_multi(mysqli $db, string $sql): void {
    $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql) ?? $sql;   // database.sql starts with a UTF-8 BOM
    if (!$db->multi_query($sql)) throw new RuntimeException('SQL failed: ' . $db->error);
    do { if ($r = $db->store_result()) $r->free(); } while ($db->more_results() && $db->next_result());
    if ($db->errno) throw new RuntimeException('SQL failed: ' . $db->error);
}
/** $body: array -> form post; string -> raw body with $type (default application/json); null -> none. */
function e2e_http(string $url, string $method = 'GET', array|string|null $body = null, ?string $cookie = null, string $type = 'application/json'): array {
    $ch = curl_init($url); $headers = [];
    if ($cookie !== null && $cookie !== '') $headers[] = 'Cookie: ' . $cookie;
    if (is_string($body)) $headers[] = 'Content-Type: ' . $type;
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_POSTFIELDS => $body, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 180]);
    $raw = (string)curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $size = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    $head = substr($raw, 0, $size); $cookieOut = '';
    if (preg_match('/^Set-Cookie:\s*([^;\r\n]+)/mi', $head, $match)) $cookieOut = $match[1];
    $bodyOut = substr($raw, $size);
    $json = json_decode($bodyOut, true);
    return ['status' => $status, 'headers' => $head, 'body' => $bodyOut, 'json' => is_array($json) ? $json : []];
}
function e2e_stop($process): void {
    if (!is_resource($process)) return;
    $state = proc_get_status($process);
    if ($state['running']) {
        if (PHP_OS_FAMILY === 'Windows') @exec('taskkill /PID ' . (int)$state['pid'] . ' /T /F 2>NUL');
        proc_terminate($process);
    }
    proc_close($process);
}
/** Every scalar (and key) of a decoded structure that contains one of $needles. */
function e2e_leaks(mixed $value, array $needles, string $path = ''): array {
    $out = [];
    if (is_array($value)) {
        foreach ($value as $k => $v) {
            foreach ($needles as $n) if (stripos((string)$k, $n) !== false) $out[] = $path . '/' . $k;
            $out = array_merge($out, e2e_leaks($v, $needles, $path . '/' . $k));
        }
        return $out;
    }
    foreach ($needles as $n) if ($value !== null && stripos((string)$value, $n) !== false) $out[] = $path . '=' . $value;
    return $out;
}

$cleanup = static function () use (&$admin, &$scratchDb, &$server, $scratch, $prefix, $sessionPath, $prepend, $serverLog, $complaintsDir, $storage): void {
    e2e_stop($server); $server = null;
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
    // ===================================================================== setup
    $admin = new mysqli($DB_HOST, $DB_USER, $DB_PASS);
    if ($admin->connect_errno) throw new RuntimeException('MySQL unavailable.');
    $realCounts = [];
    foreach (['complaints', 'complaint_types', 'complaint_parties', 'complaint_hearings', 'complaint_documents', 'audit_log', 'sms_log'] as $table) {
        $realCounts[$table] = (int)e2e_value($admin, "SELECT COUNT(*) FROM `{$realDatabase}`.`{$table}`");
    }
    if (!$admin->query("CREATE DATABASE `{$scratch}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) throw new RuntimeException($admin->error);
    $scratchDb = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $scratch);
    $scratchDb->set_charset('utf8mb4');
    e2e_multi($scratchDb, (string)file_get_contents($root . '/database/database.sql'));
    $DB_NAME = $scratch;
    $db = get_db();

    $password = 'E2e-pass-2026!';
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $official = static function (string $user, string $role, string $fname) use ($scratchDb, $hash): int {
        $stmt = $scratchDb->prepare("INSERT INTO barangay_officials (fname,lname,username,role,address,contact,password,position,status) VALUES (?,'Official',?,?,'Purok 1','',?,'Clerk','active')");
        $stmt->bind_param('ssss', $fname, $user, $role, $hash); $stmt->execute(); $id = (int)$stmt->insert_id; $stmt->close();
        return $id;
    };
    $staffId = $official('e2e-staff', 'staff', 'Stella');
    $adminId = $official('e2e-admin', 'admin', 'Adam');
    $householdId = (int)$scratchDb->query("INSERT INTO households (household_code,address,household_size) VALUES ('E2E-" . getmypid() . "','Purok 2',3)") ? (int)$scratchDb->insert_id : 0;
    $resident = static function (string $f, string $l, string $user, string $address, string $contact) use ($scratchDb, $hash, $householdId): int {
        $stmt = $scratchDb->prepare("INSERT INTO residents (fname,lname,username,address,contact,password,status,household_id) VALUES (?,?,?,?,?,?,'active',?)");
        $stmt->bind_param('ssssssi', $f, $l, $user, $address, $contact, $hash, $householdId); $stmt->execute(); $id = (int)$stmt->insert_id; $stmt->close();
        return $id;
    };
    $rosaId = $resident('Rosa', 'Filer', 'e2e-rosa', 'E2E-SECRET-ADDR-ROSA', '+639171110091');
    $benId = $resident('Ben', 'Boundary', 'e2e-ben', 'E2E-SECRET-ADDR-BEN', '+639171110092');
    $carlaId = $resident('Carla', 'Other', 'e2e-carla', 'Purok 3', '+639171110093');
    $scratchDb->query("UPDATE households SET head_resident_id={$rosaId} WHERE id={$householdId}");

    // Summons template v1 in temp storage (build script, --apply, scratch database only).
    $proc = proc_open([PHP_BINARY, $root . '/project-support/scripts/build-summons-template.php', '--database=' . $scratch, '--apply'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $buildOut = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $buildCode = proc_close($proc);
    e2e_check('setup: scratch database from database.sql; Summons v1 built and active in temp storage',
        $buildCode === 0 && str_contains($buildOut, 'activated Summons v1'), $buildOut);

    mkdir($sessionPath, 0700, true);
    file_put_contents($prepend, "<?php foreach (['GEMINI_API_KEY', 'GEMINI_MODEL', 'HTTPSMS_API_KEY', 'HTTPSMS_FROM_NUMBER'] as \$k) { putenv(\$k . '='); \$_ENV[\$k] = ''; \$_SERVER[\$k] = ''; }\n"
        . "if (!str_starts_with((string)getenv('BARANGAY_DB_NAME'), 'capstone_e2e_')) { http_response_code(500); exit; }\n"
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
        if ($base === null) { e2e_stop($server); $server = null; }
    }
    if ($base === null) throw new RuntimeException('Endpoint server did not start.');
    $login = static function (string $action, string $username) use ($base, $password): string {
        $r = e2e_http($base . '/pages/login.php', 'POST', ['action' => $action, 'username' => $username, 'password' => $password]);
        preg_match('/^Set-Cookie:\s*([^;\r\n]+)/mi', $r['headers'], $m);
        return $r['status'] === 302 ? (string)($m[1] ?? '') : '';
    };
    $staff = $login('official-login', 'e2e-staff');
    $adminC = $login('official-login', 'e2e-admin');
    $rosa = $login('resident-login', 'e2e-rosa');
    $ben = $login('resident-login', 'e2e-ben');
    $carla = $login('resident-login', 'e2e-carla');
    e2e_check('setup: staff, admin and three resident logins', $staff !== '' && $adminC !== '' && $rosa !== '' && $ben !== '' && $carla !== '');
    $post = static fn(string $path, array $body, ?string $cookie): array => e2e_http($base . '/api/' . $path, 'POST', (string)json_encode($body), $cookie);
    $get = static fn(string $path, ?string $cookie): array => e2e_http($base . '/api/' . $path, 'GET', null, $cookie);
    $listFor = static function (?string $cookie, int $id) use ($get): ?array {
        foreach (($get('complaints/list.php', $cookie)['json']['complaints'] ?? []) as $row) if ((int)$row['id'] === $id) return $row;
        return null;
    };
    $tz = new DateTimeZone('Asia/Manila');
    $day = (new DateTimeImmutable('now', $tz))->modify('+7 days');
    if ($day->format('N') === '7') $day = $day->modify('+1 day');
    $day2 = $day->modify('+1 day');
    if ($day2->format('N') === '7') $day2 = $day2->modify('+1 day');
    $secrets = ['E2E-SECRET', '0917 000', '+63917000', 'E2E-REF'];

    // ===================================================================== 1
    e2e_step(1, 'Staff creates the complaint type "Boundary Dispute" (respondent required) with a select and a text field.');
    $made = $post('complaint_types/save_type.php', ['type_name' => 'Boundary Dispute', 'respondent_mode' => 'required', 'description' => 'Disputes over lot boundaries'], $staff);
    $typeId = (int)($made['json']['type']['id'] ?? 0);
    $fSelect = $post('complaint_types/save_field.php', ['type_id' => $typeId, 'field_key' => 'boundary_side', 'label' => 'Which side', 'field_type' => 'select', 'is_required' => true,
        'options' => [['value' => 'front', 'label' => 'Front'], ['value' => 'back', 'label' => 'Back'], ['value' => 'side', 'label' => 'Side']]], $staff);
    $fText = $post('complaint_types/save_field.php', ['type_id' => $typeId, 'field_key' => 'lot_details', 'label' => 'Lot details', 'field_type' => 'text', 'max_length' => 150], $staff);
    $fields = $get('complaint_types/fields.php?type_id=' . $typeId, $rosa);
    e2e_check('type created (required respondents) and both fields saved; residents can load the form fields',
        $made['status'] === 200 && $typeId > 0 && ($made['json']['type']['respondent_mode'] ?? '') === 'required'
        && $fSelect['status'] === 200 && $fText['status'] === 200 && $fields['status'] === 200
        && array_column($fields['json']['type']['fields'] ?? [], 'key') === ['boundary_side', 'lot_details'], $made['body'] . $fSelect['body'] . $fields['body']);

    // ===================================================================== 2
    e2e_step(2, 'Resident Rosa files it against two respondents.');
    $filing = $post('complaints/create.php', ['complaint_type_id' => $typeId, 'location_text' => 'Purok 2 lot line', 'description' => 'The neighbour moved the fence one metre into our lot.',
        'severity' => 'medium', 'extra_fields' => ['boundary_side' => 'back', 'lot_details' => 'Lot 12 block 3'],
        'respondents' => [['full_name' => 'E2E Pedro Neighbour', 'address' => 'E2E-SECRET-ADDR-PEDRO', 'contact_no' => '0917 000 1111'],
                          ['full_name' => 'E2E Lina Neighbour', 'address' => null, 'contact_no' => null]]], $rosa);
    $cid = (int)($filing['json']['id'] ?? 0);
    $ref = (string)($filing['json']['reference_no'] ?? '');
    $rosaView = $listFor($rosa, $cid);
    e2e_check('filed with a reference number; Rosa\'s list shows the two respondents by name only (no address, contact or ids)',
        $filing['status'] === 200 && $cid > 0 && $ref !== '' && $rosaView !== null
        && array_column(array_filter($rosaView['parties'], static fn($p) => $p['role'] === 'respondent'), 'full_name') === ['E2E Pedro Neighbour', 'E2E Lina Neighbour']
        && array_map(static fn($p) => array_keys($p), $rosaView['parties']) === array_fill(0, count($rosaView['parties']), ['role', 'full_name']),
        $filing['body'] . ' :: ' . json_encode($rosaView['parties'] ?? null));
    e2e_check('the extra fields were stored for the new type', str_contains((string)e2e_value($scratchDb, "SELECT extra_fields_json FROM complaints WHERE id={$cid}"), 'Lot 12 block 3'));

    // ===================================================================== 3
    e2e_step(3, 'Staff links one respondent to registered resident Ben. (The third, unregistered respondent is added right after scheduling in step 4, so step 5 can show the refusal.)');
    $partiesNow = static fn() => $get('complaints/parties_list.php?complaint_id=' . $cid, $staff)['json']['parties'] ?? [];
    $pedro = array_values(array_filter($partiesNow(), static fn($p) => $p['full_name'] === 'E2E Pedro Neighbour'))[0] ?? [];
    $link = $post('complaints/party_link.php', ['id' => (int)($pedro['id'] ?? 0), 'version' => (int)($pedro['version'] ?? 0), 'resident_id' => $benId], $staff);
    e2e_check('respondent linked to Ben (registered)', $link['status'] === 200 && (int)e2e_value($scratchDb, 'SELECT resident_id FROM complaint_parties WHERE id=' . (int)($pedro['id'] ?? 0)) === $benId, $link['body']);

    // ===================================================================== 4
    e2e_step(4, 'Staff schedules mediation session 1.');
    $smsMark = (int)e2e_value($scratchDb, 'SELECT COALESCE(MAX(id),0) FROM sms_log');
    $sched = $post('hearings/schedule.php', ['complaint_id' => $cid, 'date' => $day->format('Y-m-d'), 'start_time' => '09:00', 'duration_minutes' => 60,
        'venue' => 'The Covered Court', 'presiding_official_id' => $staffId, 'request_key' => bin2hex(random_bytes(16))], $staff);
    $s1 = (int)($sched['json']['hearing']['id'] ?? 0);
    $caseNo = (string)e2e_value($scratchDb, "SELECT case_no FROM complaints WHERE id={$cid}");
    $smsTo = array_map('intval', array_column(e2e_rows($scratchDb, "SELECT resident_id FROM sms_log WHERE id > {$smsMark} AND endpoint='complaint_hearing_scheduled' ORDER BY resident_id"), 'resident_id'));
    e2e_check('session 1 scheduled; case number BC-' . $day->format('Y') . '-NNNN; complaint is Investigating',
        $sched['status'] === 200 && $s1 > 0 && preg_match('/^BC-\d{4}-\d{4}$/', $caseNo) === 1
        && e2e_value($scratchDb, "SELECT status FROM complaints WHERE id={$cid}") === 'investigating', $sched['body']);
    $expectSms = [$rosaId, $benId]; sort($expectSms);
    e2e_check('SMS only to the registered parties (Rosa and Ben); none to unregistered people', $smsTo === $expectSms, json_encode($smsTo));

    $add = $post('complaints/party_save.php', ['complaint_id' => $cid, 'role' => 'respondent', 'full_name' => 'E2E Third Person', 'address' => 'E2E-SECRET-ADDR-THIRD', 'contact_no' => null], $staff);
    $third = (int)($add['json']['party']['id'] ?? 0);
    $active = $partiesNow();
    e2e_check('(3) third respondent (unregistered) added after scheduling; 4 active parties, one registered respondent',
        $add['status'] === 200 && $third > 0 && count($active) === 4
        && count(array_filter($active, static fn($p) => $p['role'] === 'respondent' && !empty($p['resident_id']))) === 1, $add['body']);
    $respIds = array_map(static fn($p) => (int)$p['id'], array_values(array_filter($active, static fn($p) => $p['role'] === 'respondent')));
    $origIds = array_values(array_diff($respIds, [$third]));

    // ===================================================================== 5
    e2e_step(5, 'Staff generates summonses for the two original respondents; the third is refused.');
    $docIds1 = [];
    foreach ($origIds as $pid) {
        $g = $post('hearings/summons_generate.php', ['hearing_id' => $s1, 'party_id' => $pid], $staff);
        if ($g['status'] === 200) $docIds1[] = (int)$g['json']['document']['id'];
    }
    $refused = $post('hearings/summons_generate.php', ['hearing_id' => $s1, 'party_id' => $third], $staff);
    e2e_check('two summonses generated for session 1', count($docIds1) === 2, json_encode($docIds1));
    e2e_check('third respondent refused with the reschedule message (422)', $refused['status'] === 422 && ($refused['json']['message'] ?? '') === SUMMONS_MSG_ADDED_AFTER, $refused['body']);
    $pdf1 = e2e_http($base . '/api/hearings/summons_view.php?id=' . ($docIds1[0] ?? 0) . '&format=pdf', 'GET', null, $staff);
    $uuid1 = (string)e2e_value($scratchDb, 'SELECT file_uuid FROM complaint_documents WHERE id=' . (int)($docIds1[0] ?? 0));
    $docx1 = $complaintsDir . DIRECTORY_SEPARATOR . $uuid1 . '.docx';
    $zip = new ZipArchive(); $docText = '';
    if ($zip->open($docx1, ZipArchive::RDONLY) === true) { $docText = implode("\n", document_template_paragraph_text((string)$zip->getFromName('word/document.xml'))); $zip->close(); }
    e2e_check('summons PDF opens; the venue "The Covered Court" is printed without its leading "The"',
        $pdf1['status'] === 200 && str_starts_with($pdf1['body'], '%PDF') && str_contains($docText, 'Covered Court,') && !str_contains($docText, 'The Covered Court')
        && str_contains($docText, $caseNo), mb_substr($docText, 0, 400));

    // ===================================================================== 6
    e2e_step(6, 'Staff reschedules (session 2) and generates summonses for all three respondents.');
    $s1Version = (int)e2e_value($scratchDb, "SELECT version FROM complaint_hearings WHERE id={$s1}");
    $re = $post('hearings/reschedule.php', ['hearing_id' => $s1, 'version' => $s1Version, 'date' => $day2->format('Y-m-d'), 'start_time' => '10:00', 'duration_minutes' => 60,
        'venue' => 'Barangay Hall', 'presiding_official_id' => $staffId, 'reason' => 'E2E-SECRET-REASON third respondent added', 'request_key' => bin2hex(random_bytes(16))], $staff);
    $s2 = (int)($re['json']['hearing']['id'] ?? 0);
    $docIds2 = [];
    foreach ($respIds as $pid) {
        $g = $post('hearings/summons_generate.php', ['hearing_id' => $s2, 'party_id' => $pid], $staff);
        if ($g['status'] === 200) $docIds2[] = (int)$g['json']['document']['id'];
    }
    e2e_check('session 2 scheduled; session 1 is "rescheduled"', $re['status'] === 200 && $s2 > 0
        && e2e_value($scratchDb, "SELECT status FROM complaint_hearings WHERE id={$s1}") === 'rescheduled'
        && (int)e2e_value($scratchDb, "SELECT session_no FROM complaint_hearings WHERE id={$s2}") === 2, $re['body']);
    e2e_check('three summonses generated for session 2', count($docIds2) === 3, json_encode($docIds2));
    $old = array_map(static fn(int $id) => e2e_http($base . '/api/hearings/summons_view.php?id=' . $id . '&format=pdf', 'GET', null, $staff)['status'], $docIds1);
    e2e_check('session 1 summons copies remain viewable (200)', $old === [200, 200], json_encode($old));

    // ===================================================================== 7
    e2e_step(7, 'After the session time, staff records attendance, outcome Settled and a summary.');
    // TEST-ONLY: move session 2 into the past so it can be recorded (the API refuses to record a session that has not started).
    $pastStart = (new DateTimeImmutable('now', $tz))->modify('-2 hours')->format('Y-m-d H:i:s');
    $pastEnd = (new DateTimeImmutable('now', $tz))->modify('-1 hour')->format('Y-m-d H:i:s');
    $scratchDb->query("UPDATE complaint_hearings SET starts_at='{$pastStart}', ends_at='{$pastEnd}' WHERE id={$s2}");
    $attendance = [];
    foreach (e2e_rows($scratchDb, "SELECT party_id FROM complaint_hearing_attendance WHERE hearing_id={$s2}") as $row) $attendance[(string)$row['party_id']] = 'present';
    $s2Version = (int)e2e_value($scratchDb, "SELECT version FROM complaint_hearings WHERE id={$s2}");
    $publicSummary = 'Both sides agreed to restore the original fence line within two weeks.';
    $rec = $post('hearings/record.php', ['hearing_id' => $s2, 'version' => $s2Version, 'attendance' => $attendance, 'outcome' => 'settled',
        'public_summary' => $publicSummary, 'private_notes' => 'E2E-SECRET-NOTES respondent was upset', 'complete' => true], $staff);
    e2e_check('session 2 completed with outcome settled; attendance recorded for all 4 parties',
        $rec['status'] === 200 && e2e_value($scratchDb, "SELECT status FROM complaint_hearings WHERE id={$s2}") === 'completed'
        && e2e_value($scratchDb, "SELECT outcome FROM complaint_hearings WHERE id={$s2}") === 'settled' && count($attendance) === 4
        && (int)e2e_value($scratchDb, "SELECT COUNT(*) FROM complaint_hearing_attendance WHERE hearing_id={$s2} AND status='present'") === 4, $rec['body']);

    // ===================================================================== 8
    e2e_step(8, 'Staff resolves the complaint; Rosa sees the public summary only.');
    $resolve = $post('complaints/notify_resolved.php', ['id' => $cid, 'official_note' => 'Settled at mediation.'], $staff);
    $rosaList = $get('complaints/list.php', $rosa);
    $rosaRow = null; foreach ($rosaList['json']['complaints'] ?? [] as $row) if ((int)$row['id'] === $cid) $rosaRow = $row;
    $sessions = $rosaRow['hearings'] ?? [];
    $completed = array_values(array_filter($sessions, static fn($s) => ($s['status'] ?? '') === 'completed'));
    e2e_check('complaint resolved', $resolve['status'] === 200 && e2e_value($scratchDb, "SELECT status FROM complaints WHERE id={$cid}") === 'resolved', $resolve['body']);
    e2e_check('Rosa sees the public summary of the completed session; no private notes, no change reason, no summons anywhere in her list',
        count($completed) === 1 && ($completed[0]['public_summary'] ?? '') === $publicSummary
        && !str_contains($rosaList['body'], 'E2E-SECRET-NOTES') && !str_contains($rosaList['body'], 'E2E-SECRET-REASON')
        && stripos($rosaList['body'], 'summons') === false && !str_contains($rosaList['body'], 'file_uuid'), json_encode($sessions));

    // ===================================================================== 9
    e2e_step(9, 'Walk-in: an official files for an unregistered complainant against registered resident Ben.');
    $walk = $post('complaints/create_official.php', ['complaint_type_id' => $typeId, 'location_text' => 'Purok 2 drainage line', 'description' => 'Walk-in: wall built over the shared drainage line.',
        'severity' => 'low', 'extra_fields' => ['boundary_side' => 'side'],
        'complainants' => [['full_name' => 'E2E Walkin Complainant', 'address' => 'E2E-SECRET-ADDR-WALKIN', 'contact_no' => '0917 000 2222']],
        'respondents' => [['resident_id' => $benId]]], $staff);
    $wid = (int)($walk['json']['id'] ?? 0);
    e2e_check('walk-in filed and listed for officials', $walk['status'] === 200 && $wid > 0 && $listFor($staff, $wid) !== null, $walk['body']);
    e2e_check('the walk-in is in no resident\'s list (Rosa, Ben the respondent, Carla)',
        $listFor($rosa, $wid) === null && $listFor($ben, $wid) === null && $listFor($carla, $wid) === null);

    // ===================================================================== 10
    e2e_step(10, 'Complaints report and AI payload.');
    $report = $get('stats/complaints_report.php', $staff);
    $row = array_values(array_filter($report['json']['by_category'] ?? [], static fn($r) => ($r['type_id'] ?? null) === $typeId))[0] ?? [];
    $med = $report['json']['mediation'] ?? [];
    e2e_check('by_category: "Boundary Dispute" with 2 complaints', ($row['category'] ?? '') === 'Boundary Dispute' && ($row['count'] ?? 0) === 2, json_encode($row));
    e2e_check('mediation: 1 completed, 1 rescheduled, settled 1, settlement rate 100.0, 1 case number, 5 current summonses and 0 superseded',
        ($med['sessions'] ?? null) === ['scheduled' => 0, 'completed' => 1, 'cancelled' => 0, 'rescheduled' => 1]
        && ($med['outcomes']['settled'] ?? 0) === 1 && (float)($med['settlement_rate'] ?? 0) === 100.0
        && ($med['complaints_with_case_no'] ?? 0) === 1 && ($med['summons_issued'] ?? 0) === 5, json_encode($med));
    complaint_types_cache_reset();
    $payload = complaint_ai_payload(complaint_report_data($db));
    $leaks = e2e_leaks($payload, array_merge($secrets, ['E2E Pedro', 'E2E Lina', 'E2E Third', 'E2E Walkin', 'Rosa', 'Ben Boundary', $ref, $caseNo]));
    e2e_check('AI payload has no names, addresses, contacts, notes, reasons, reference or case numbers', $leaks === [], implode(' | ', $leaks));
    e2e_check('report body has none of them either', e2e_leaks($report['json'], array_merge($secrets, ['E2E Pedro', 'E2E Walkin', $ref, $caseNo])) === []);

    // ===================================================================== 11
    e2e_step(11, 'Staff renames "Boundary Dispute" to "Lot Boundary Dispute", then deactivates it.');
    $typeVersion = static function () use ($get, $staff, $typeId): int {
        foreach (($get('complaint_types/list.php', $staff)['json']['types'] ?? []) as $t) if ((int)$t['id'] === $typeId) return (int)$t['version'];
        return 0;
    };
    $ren = $post('complaint_types/save_type.php', ['id' => $typeId, 'version' => $typeVersion(), 'type_name' => 'Lot Boundary Dispute', 'respondent_mode' => 'required'], $staff);
    $off = $post('complaint_types/toggle_type.php', ['id' => $typeId, 'version' => $typeVersion(), 'is_active' => false], $staff);
    $staffRow = $listFor($staff, $cid); $rosaRow2 = $listFor($rosa, $cid);
    $report2 = $get('stats/complaints_report.php', $staff);
    $rows2 = array_values(array_filter($report2['json']['by_category'] ?? [], static fn($r) => ($r['type_id'] ?? null) === $typeId));
    e2e_check('renamed and deactivated', $ren['status'] === 200 && $off['status'] === 200, $ren['body'] . $off['body']);
    e2e_check('the old complaint shows the new name in the official and resident lists',
        ($staffRow['type_name'] ?? '') === 'Lot Boundary Dispute' && ($rosaRow2['type_name'] ?? '') === 'Lot Boundary Dispute', json_encode([$staffRow['type_name'] ?? null, $rosaRow2['type_name'] ?? null]));
    e2e_check('the report keeps ONE row for the type, with the new name, 2 complaints, marked inactive',
        count($rows2) === 1 && $rows2[0]['category'] === 'Lot Boundary Dispute' && $rows2[0]['count'] === 2 && $rows2[0]['is_active'] === false, json_encode($rows2));
    $residentPage = e2e_http($base . '/pages/resident.php', 'GET', null, $rosa);
    $fieldsOff = $get('complaint_types/fields.php?type_id=' . $typeId, $rosa);
    e2e_check('the resident form no longer offers it (page has no option for it; fields.php answers 404)',
        $residentPage['status'] === 200 && !str_contains($residentPage['body'], 'Boundary Dispute') && $fieldsOff['status'] === 404, (string)$residentPage['status']);

    // ===================================================================== 12
    e2e_step(12, 'Admin makes the complaint public on the community board.');
    $vis = $post('complaints/set_visibility.php', ['complaint_id' => $cid, 'is_public' => true, 'expected_is_public' => false, 'expected_duplicate_of_id' => null], $adminC);
    $board = $get('complaints/public_list.php', $carla);
    $card = array_values(array_filter($board['json']['complaints'] ?? [], static fn($c) => (int)$c['id'] === $cid))[0] ?? [];
    $keys = array_keys($card); sort($keys);
    e2e_check('card shows the type name and no party data',
        $vis['status'] === 200 && $keys === ['filed_on', 'id', 'published_report_count', 'severity', 'status', 'type_id', 'type_name']
        && ($card['type_name'] ?? '') === 'Lot Boundary Dispute'
        && e2e_leaks($board['json'], array_merge($secrets, ['E2E Pedro', 'E2E Lina', 'E2E Third', 'E2E Walkin', 'Rosa', 'Ben', $ref, $caseNo, 'fence'])) === [],
        $vis['body'] . ' :: ' . json_encode($card));

    // ===================================================================== 13
    e2e_step(13, 'Phase 10 fee variant: a type with a fee; issue -> blocked -> pay -> schedule -> summons.');
    $feeTypeMade = $post('complaint_types/save_type.php', ['type_name' => 'Fee Dispute', 'respondent_mode' => 'optional', 'default_fee' => '30.00'], $staff);
    $feeTypeId = (int)($feeTypeMade['json']['type']['id'] ?? 0);
    $feeFiling = $post('complaints/create.php', ['complaint_type_id' => $feeTypeId, 'location_text' => 'Purok 2 gate', 'description' => 'Fee variant: the neighbour blocks the shared gate.',
        'severity' => 'low', 'respondents' => [['full_name' => 'E2E Fee Respondent', 'address' => null, 'contact_no' => null]]], $rosa);
    $fcid = (int)($feeFiling['json']['id'] ?? 0);
    $feeBill = $get('complaints/bill_get.php?complaint_id=' . $fcid, $staff)['json'];
    $feeSms = (int)e2e_value($scratchDb, 'SELECT COALESCE(MAX(id),0) FROM sms_log');
    $feeIssue = $post('complaints/bill_issue.php', ['complaint_id' => $fcid, 'amount' => (string)($feeBill['default_fee'] ?? ''), 'billing_note' => 'Mediation fee'], $staff);
    e2e_check('fee type created (30.00); Rosa files; bill issued with the prefilled 30.00; SMS to Rosa',
        $feeTypeMade['status'] === 200 && $fcid > 0 && ($feeBill['default_fee'] ?? '') === '30.00' && $feeIssue['status'] === 200
        && ($feeIssue['json']['bill']['amount'] ?? '') === '30.00'
        && array_map('intval', array_column(e2e_rows($scratchDb, "SELECT resident_id FROM sms_log WHERE id > {$feeSms} AND endpoint='complaint_bill_issued'"), 'resident_id')) === [$rosaId],
        $feeTypeMade['body'] . $feeIssue['body']);
    $feeSchedule = static fn() => $post('hearings/schedule.php', ['complaint_id' => $fcid, 'date' => $day->format('Y-m-d'), 'start_time' => '15:00', 'duration_minutes' => 60,
        'venue' => 'Barangay Hall', 'presiding_official_id' => $staffId, 'request_key' => bin2hex(random_bytes(16))], $staff);
    $blocked = $feeSchedule();
    e2e_check('schedule while unpaid -> 409 "The complaint fee must be paid or waived first."',
        $blocked['status'] === 409 && ($blocked['json']['message'] ?? '') === 'The complaint fee must be paid or waived first.', $blocked['body']);
    $feePaid = $post('complaints/bill_mark_paid.php', ['complaint_id' => $fcid, 'version' => 1, 'receipt_no' => 'OR-E2E-1'], $staff);
    $feeSched = $feeSchedule();
    $feeHearing = (int)($feeSched['json']['hearing']['id'] ?? 0);
    $feeResp = (int)e2e_value($scratchDb, "SELECT id FROM complaint_parties WHERE complaint_id={$fcid} AND role='respondent' LIMIT 1");
    $feeSummons = $post('hearings/summons_generate.php', ['hearing_id' => $feeHearing, 'party_id' => $feeResp], $staff);
    $feePdf = e2e_http($base . '/api/hearings/summons_view.php?id=' . (int)($feeSummons['json']['document']['id'] ?? 0) . '&format=pdf', 'GET', null, $staff);
    e2e_check('mark paid -> schedule 200 -> summons generated and opens as PDF',
        $feePaid['status'] === 200 && $feeSched['status'] === 200 && $feeHearing > 0 && $feeSummons['status'] === 200
        && $feePdf['status'] === 200 && str_starts_with($feePdf['body'], '%PDF'), $feePaid['body'] . $feeSched['body'] . $feeSummons['body']);
    $rosaFee = $listFor($rosa, $fcid)['bill'] ?? [];
    e2e_check('Rosa sees the fee as paid with the note; no receipt number',
        ($rosaFee['status'] ?? '') === 'paid' && ($rosaFee['billing_note'] ?? '') === 'Mediation fee' && !array_key_exists('receipt_no', $rosaFee), json_encode($rosaFee));

    // ===================================================================== D) guard sweep
    echo "\nD. Guard sweep: every endpoint added or changed in P2-P10.\n";
    // [path, method, session (official|resident|any), strict JSON body]
    $guards = [
        ['complaints/create.php', 'POST', 'resident', true],
        ['complaints/create_official.php', 'POST', 'official', true],
        ['complaints/list.php', 'GET', 'any', false],
        ['complaints/public_list.php', 'GET', 'resident', false],
        ['complaints/update_status.php', 'POST', 'official', true],
        ['complaints/notify_resolved.php', 'POST', 'official', true],
        ['complaints/parties_list.php', 'GET', 'official', false],
        ['complaints/resident_search.php', 'GET', 'official', false],
        ['complaints/party_save.php', 'POST', 'official', true],
        ['complaints/party_link.php', 'POST', 'official', true],
        ['complaints/party_remove.php', 'POST', 'official', true],
        ['complaint_types/list.php', 'GET', 'official', false],
        ['complaint_types/fields.php', 'GET', 'any', false],
        ['complaint_types/save_type.php', 'POST', 'official', true],
        ['complaint_types/save_field.php', 'POST', 'official', true],
        ['complaint_types/toggle_type.php', 'POST', 'official', true],
        ['complaint_types/toggle_field.php', 'POST', 'official', true],
        ['hearings/list.php', 'GET', 'official', false],
        ['hearings/check.php', 'GET', 'official', false],
        ['hearings/for_complaint.php', 'GET', 'official', false],
        ['hearings/needs_recording.php', 'GET', 'official', false],
        ['hearings/officials.php', 'GET', 'official', false],
        ['hearings/summons_list.php', 'GET', 'official', false],
        ['hearings/summons_view.php', 'GET', 'official', false],
        ['hearings/schedule.php', 'POST', 'official', true],
        ['hearings/reschedule.php', 'POST', 'official', true],
        ['hearings/cancel.php', 'POST', 'official', true],
        ['hearings/record.php', 'POST', 'official', true],
        ['hearings/summons_generate.php', 'POST', 'official', true],
        ['stats/complaints_report.php', 'GET', 'official', false],
        ['ai/analyze_complaints.php', 'POST', 'official', true],
        ['complaints/bill_get.php', 'GET', 'official', false],
        ['complaints/bill_issue.php', 'POST', 'official', true],
        ['complaints/bill_update.php', 'POST', 'official', true],
        ['complaints/bill_mark_paid.php', 'POST', 'official', true],
        ['complaints/bill_waive.php', 'POST', 'official', true],
    ];
    $table = [];
    $plainJsonAccepted = [];
    $ok = static fn(int $s, array $allowed): bool => in_array($s, $allowed, true);
    foreach ($guards as [$path, $method, $session, $strict]) {
        $url = $base . '/api/' . $path;
        $right = $session === 'resident' ? $carla : $staff;
        $wrong = $session === 'official' ? $carla : ($session === 'resident' ? $staff : null);
        $loggedOut = e2e_http($url, $method, $method === 'POST' ? '{}' : null, null)['status'];
        $wrongRole = $wrong === null ? null : e2e_http($url, $method, $method === 'POST' ? '{}' : null, $wrong)['status'];
        $otherMethod = $method === 'POST' ? 'GET' : 'POST';
        $wrongMethod = e2e_http($url, $otherMethod, $otherMethod === 'POST' ? '{}' : null, $right)['status'];
        $plain = $listBody = null;
        if ($strict) {
            $plain = e2e_http($url, 'POST', 'x=1', $right, 'text/plain')['status'];
            $listBody = e2e_http($url, 'POST', '[1,2]', $right)['status'];
            $asText = e2e_http($url, 'POST', '{"id":999999999}', $right, 'text/plain')['status'];
            if (!in_array($asText, [415], true)) $plainJsonAccepted[] = $path . ' (' . $asText . ')';
        }
        $pass = $loggedOut === 401 && ($wrongRole === null || $ok($wrongRole, [401, 403])) && $wrongMethod === 405
            && (!$strict || ($ok($plain, [415, 422]) && $listBody === 422));
        $table[] = sprintf('%-34s %-4s %-8s out=%d role=%s method=%d%s', $path, $method, $session, $loggedOut, $wrongRole === null ? '-' : (string)$wrongRole, $wrongMethod,
            $strict ? sprintf(' text/plain=%d list=%d', $plain, $listBody) : '');
        e2e_check('guard ' . $path . ': logged out 401, wrong role 401/403, wrong method 405' . ($strict ? ', non-JSON 415/422, JSON list 422' : ''),
            $pass, end($table));
    }
    echo "   --- guard table ---\n   " . implode("\n   ", $table) . "\n";
    if ($plainJsonAccepted !== []) $findings[] = 'A valid JSON object sent as text/plain is not refused with 415 by: ' . implode(', ', $plainJsonAccepted);
    e2e_check('the three endpoints made strict in Phase 9 (create, update_status, notify_resolved) refuse JSON sent as text/plain with 415',
        array_filter($plainJsonAccepted, static fn(string $s): bool => (bool)preg_match('#^complaints/(create|update_status|notify_resolved)\.php#', $s)) === [], implode(', ', $plainJsonAccepted));

    // File access: paths come from database ids only.
    $attachmentId = 0;
    $scratchDb->query("INSERT INTO complaint_attachments (complaint_id, file_path, mime, byte_size, uploaded_by_type, uploaded_by_id)
        VALUES ({$wid}, 'e2e-missing/x.png', 'image/png', 10, 'official', {$staffId})");
    $attachmentId = (int)$scratchDb->insert_id;
    $views = [
        'unknown id' => e2e_http($base . '/api/hearings/summons_view.php?id=999999&format=pdf', 'GET', null, $staff)['status'],
        'attachment id as summons id' => e2e_http($base . '/api/hearings/summons_view.php?id=' . max($attachmentId, 1) . '0000&format=pdf', 'GET', null, $staff)['status'],
        'non-numeric id' => e2e_http($base . '/api/hearings/summons_view.php?id=1%20OR%201&format=pdf', 'GET', null, $staff)['status'],
        'path in format' => e2e_http($base . '/api/hearings/summons_view.php?id=' . ($docIds2[0] ?? 0) . '&format=..%2F..%2Fdb.php', 'GET', null, $staff)['status'],
        'path as id' => e2e_http($base . '/api/hearings/summons_view.php?id=..%2F..%2Fdb.php', 'GET', null, $staff)['status'],
    ];
    e2e_check('summons_view: unknown id, foreign id, non-numeric id, path in format or id -> 404',
        array_filter($views, static fn(int $s): bool => $s !== 404) === [], json_encode($views));
    $foreign = e2e_http($base . '/api/complaints/view_attachment.php?id=' . $attachmentId, 'GET', null, $rosa)['status'];
    $summonsAsAttachment = e2e_http($base . '/api/complaints/view_attachment.php?id=' . ($docIds2[0] ?? 0) . '999', 'GET', null, $staff)['status'];
    e2e_check('view_attachment: another person\'s complaint attachment (resident) and an unknown id -> 403/404',
        in_array($foreign, [403, 404], true) && in_array($summonsAsAttachment, [403, 404], true), json_encode([$foreign, $summonsAsAttachment]));
    $scratchDb->query("DELETE FROM complaint_attachments WHERE id={$attachmentId}");

    // ===================================================================== end
    $after = [];
    foreach (array_keys($realCounts) as $t) $after[$t] = (int)e2e_value($admin, "SELECT COUNT(*) FROM `{$realDatabase}`.`{$t}`");
    echo "\nEnd.\n";
    e2e_check('real database row counts unchanged', $after === $realCounts, json_encode([$realCounts, $after]));
    e2e_check('real summon_letter.docx unchanged', hash_file('sha256', $realSource) === $realShaBefore);
    foreach ($findings as $finding) echo "   FINDING {$finding}\n";
    $exitCode = $failed === 0 ? 0 : 1;
} catch (Throwable $e) {
    e2e_check('suite ran without an unexpected error', false, get_class($e) . ': ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
    $exitCode = 1;
} finally {
    $cleanup();
}
$total = $passed + $failed;
echo "{$passed}/{$total} passed\n";
exit($exitCode);
