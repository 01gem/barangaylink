<?php
/**
 * BarangayLink - Summons suite (complaint revision Phase 7).
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * Scratch database capstone_summons_* built from database/database.sql (never
 * a copy of the real one); template and generated files in TestStorage
 * (%TEMP%/capstone_tpl_<pid>). summon_letter.docx is copied in; the real file
 * is only read and its sha256 is compared before and after (no fixed sha: the
 * letter may be edited; the build script accepts its current and its old body
 * wording, both checked here). Creates its own
 * staff official and resident, a php -S server, and removes everything after.
 * Needs LibreOffice (generation and test render). No AI, no SMS.
 *
 *   php project-support/tests/complaint-summons/run.php
 *
 * a template v1        b generate        c values         d refusals
 * e regenerate         f concurrency     g view / access  h audience
 * i failure cleanup     j Phase 9: venue article, Summons activation wording guard
 *
 * Concurrency (f) uses two CLI processes calling summons_generate(): PHP's
 * built-in server on Windows handles one request at a time, so two curl_multi
 * requests would only ever run one after the other.
 */

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
foreach (['GEMINI_API_KEY', 'GEMINI_MODEL', 'HTTPSMS_API_KEY', 'HTTPSMS_FROM_NUMBER'] as $blocked) {
    putenv($blocked . '='); $_ENV[$blocked] = ''; $_SERVER[$blocked] = '';
}
$root = dirname(__DIR__, 3);
require_once $root . '/api/common.php';
require_once $root . '/api/complaints/_community.php';
require_once $root . '/api/hearings/_summons.php';
require_once $root . '/api/documents/_manual_edit.php';
require_once $root . '/project-support/tests/_storage.php';
// The build script's pure functions (summons_build_xml) without running it.
define('SUMMONS_BUILD_LIBRARY', true);
require_once $root . '/project-support/scripts/build-summons-template.php';
mysqli_report(MYSQLI_REPORT_OFF);

$storage = new TestStorage($root);
$storage->assertActive();
$realSource = $root . DIRECTORY_SEPARATOR . 'document_templates' . DIRECTORY_SEPARATOR . 'summon_letter.docx';
$realShaBefore = hash_file('sha256', $realSource);
$tempSource = $storage->track($storage->template('summon_letter.docx'));
if (!copy($realSource, $tempSource)) throw new RuntimeException('Cannot copy summon_letter.docx into test storage.');
$complaintsDir = $storage->root . DIRECTORY_SEPARATOR . 'generated_documents' . DIRECTORY_SEPARATOR . 'complaints';

$prefix = 'capstone_summons_';
$scratch = $prefix . getmypid() . '_' . random_int(1000, 9999);
$realDatabase = $DB_NAME;
$passed = 0; $failed = 0;
$admin = null; $scratchDb = null; $server = null;
$sessionPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'summons-sessions-' . getmypid();
$prepend = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'summons-prepend-' . getmypid() . '.php';
$serverLog = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'summons-server-' . getmypid();
$child = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'summons-child-' . getmypid() . '.php';
$go = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'summons-go-' . getmypid();

function sm_check(string $name, bool $ok, string $detail = ''): void {
    global $passed, $failed;
    if ($ok) { $passed++; echo "PASS {$name}\n"; }
    else { $failed++; echo "FAIL {$name}" . ($detail === '' ? '' : ': ' . mb_substr($detail, 0, 600)) . "\n"; }
}
function sm_rows(mysqli $db, string $sql): array {
    $result = $db->query($sql);
    if (!$result) throw new RuntimeException($db->error . ' :: ' . $sql);
    return $result === true ? [] : $result->fetch_all(MYSQLI_ASSOC);
}
function sm_value(mysqli $db, string $sql): mixed {
    $rows = sm_rows($db, $sql);
    return $rows ? array_values($rows[0])[0] : null;
}
function sm_exec(mysqli $db, string $sql): int {
    if (!$db->query($sql)) throw new RuntimeException($db->error . ' :: ' . $sql);
    return (int)$db->insert_id;
}
function sm_multi(mysqli $db, string $sql): void {
    $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql) ?? $sql;
    if (!$db->multi_query($sql)) throw new RuntimeException($db->error);
    do { if ($result = $db->store_result()) $result->free(); } while ($db->more_results() && $db->next_result());
    if ($db->errno) throw new RuntimeException($db->error);
}
function sm_http(string $url, string $method = 'GET', array|string|null $body = null, ?string $cookie = null): array {
    $ch = curl_init($url); $headers = [];
    if ($cookie !== null && $cookie !== '') $headers[] = 'Cookie: ' . $cookie;
    if (is_string($body)) $headers[] = 'Content-Type: application/json';
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_POSTFIELDS => $body, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 180]);
    $raw = (string)curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $size = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    $head = substr($raw, 0, $size); $cookieOut = '';
    if (preg_match('/^Set-Cookie:\s*([^;\r\n]+)/mi', $head, $match)) $cookieOut = $match[1];
    $bodyOut = substr($raw, $size);
    $json = json_decode($bodyOut, true);
    return ['status' => $status, 'headers' => $head, 'body' => $bodyOut, 'json' => is_array($json) ? $json : [], 'cookie' => $cookieOut];
}
function sm_problem(callable $call): ?ComplaintProblem {
    try { $call(); } catch (ComplaintProblem $problem) { return $problem; }
    return null;
}
function sm_stop($process): void {
    if (!is_resource($process)) return;
    $state = proc_get_status($process);
    if ($state['running']) {
        if (PHP_OS_FAMILY === 'Windows') @exec('taskkill /PID ' . (int)$state['pid'] . ' /T /F 2>NUL');
        proc_terminate($process);
    }
    proc_close($process);
}
/** All visible text of word/document.xml of a DOCX (fallback when pdftotext is missing). */
function sm_docx_text(string $path): string {
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::RDONLY) !== true) return '';
    $xml = (string)$zip->getFromName('word/document.xml'); $zip->close();
    return implode("\n", document_template_paragraph_text($xml));
}
function sm_pdftotext(string $pdf): ?string {
    foreach (['pdftotext', 'C:\\Program Files\\Git\\ucrt64\\bin\\pdftotext.exe', 'C:\\Program Files\\Git\\mingw64\\bin\\pdftotext.exe'] as $bin) {
        $out = $pdf . '.txt';
        $cmd = '"' . $bin . '" -layout "' . $pdf . '" "' . $out . '" 2>NUL';
        @exec($cmd, $lines, $code);
        if ($code === 0 && is_file($out)) { $text = (string)file_get_contents($out); @unlink($out); return $text; }
    }
    return null;
}
function sm_dir_files(string $dir): array {
    $out = [];
    foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $path) if (is_file($path)) $out[] = basename($path);
    sort($out); return $out;
}

$cleanup = static function () use (&$admin, &$scratchDb, &$server, $scratch, $prefix, $sessionPath, $prepend, $serverLog, $child, $go, $complaintsDir, $storage): void {
    sm_stop($server); $server = null;
    if (is_dir($sessionPath)) foreach (glob($sessionPath . DIRECTORY_SEPARATOR . 'sess_*') ?: [] as $file) @unlink($file);
    @rmdir($sessionPath);
    foreach ([$prepend, $serverLog . '.out', $serverLog . '.err', $child, $go] as $file) @unlink($file);
    // Every file this suite made in temp storage: summons, new template versions, previews.
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
    $admin = new mysqli($DB_HOST, $DB_USER, $DB_PASS);
    if ($admin->connect_errno) throw new RuntimeException('MySQL unavailable.');
    $realCounts = [];
    foreach (['document_types', 'document_template_versions', 'complaints', 'audit_log'] as $table) {
        $realCounts[$table] = (int)sm_value($admin, "SELECT COUNT(*) FROM `{$realDatabase}`.`{$table}`");
    }
    if (!$admin->query("CREATE DATABASE `{$scratch}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) throw new RuntimeException($admin->error);
    $scratchDb = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $scratch);
    $scratchDb->set_charset('utf8mb4');
    $schema = (string)file_get_contents($root . '/database/database.sql');
    sm_multi($scratchDb, $schema);
    sm_multi($scratchDb, (string)file_get_contents($root . '/project-support/migrations/20261119_summons.sql'));
    $DB_NAME = $scratch; // library calls below use get_db()/db_connection() on the scratch database
    $db = get_db();
    sm_check('setup: scratch database from database.sql + 20261119 (re-run) has one Summons type with 13 fields',
        (int)sm_value($scratchDb, "SELECT COUNT(*) FROM document_types WHERE code='summons' AND audience='internal' AND sort_order=1000") === 1
        && (int)sm_value($scratchDb, "SELECT COUNT(*) FROM document_type_fields f JOIN document_types t ON t.id=f.document_type_id WHERE t.code='summons'") === 13
        && (int)sm_value($scratchDb, "SELECT COUNT(*) FROM document_template_versions v JOIN document_types t ON t.id=v.document_type_id WHERE t.code='summons'") === 0);

    // ---------------------------------------------------------------- a) template v1 (build script)
    $script = $root . '/project-support/scripts/build-summons-template.php';
    $runScript = static function (array $args) use ($script): array {
        $cmd = array_merge([PHP_BINARY, $script], $args);
        $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        return [proc_close($process), $out . $err];
    };
    [$code, $out] = $runScript(['--database=' . $scratch]);
    sm_check('a) build script dry run: exit 0, check OK, nothing written',
        $code === 0 && str_contains($out, 'template check: OK') && (int)sm_value($scratchDb, 'SELECT COUNT(*) FROM document_template_versions') === 5, $out);
    [$code, $out] = $runScript(['--database=' . $scratch, '--apply']);
    sm_check('a) build script --apply: stored, test-rendered and activated v1', $code === 0 && str_contains($out, 'activated Summons v1'), $out);
    [$code2, $out2] = $runScript(['--database=' . $scratch, '--apply']);
    sm_check('a) build script leaves no temp workspace', (glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'barangay-summons-build-*') ?: []) === []);
    sm_check('a) build script re-run: nothing to do', $code2 === 0 && str_contains($out2, 'already active') && (int)sm_value($scratchDb, "SELECT COUNT(*) FROM document_template_versions v JOIN document_types t ON t.id=v.document_type_id WHERE t.code='summons'") === 1, $out2);
    $v1 = sm_rows($scratchDb, "SELECT v.* FROM document_template_versions v JOIN document_types t ON t.id=v.document_type_id WHERE t.code='summons'")[0] ?? [];
    $typeFile = (string)sm_value($scratchDb, "SELECT template_file FROM document_types WHERE code='summons'");
    sm_check('a) v1 row: active, version 1, tpl_<type>_v1_<hex>.docx, sha matches, checked and tested, type points at it',
        ($v1['status'] ?? '') === 'active' && (int)$v1['version_no'] === 1 && preg_match('/^tpl_\d+_v1_[a-f0-9]{8}\.docx$/', (string)$v1['file_name']) === 1
        && (int)$v1['check_ok'] === 1 && (int)$v1['test_ok'] === 1 && $v1['tested_sha256'] === $v1['sha256']
        && hash_file('sha256', $storage->template((string)$v1['file_name'])) === $v1['sha256'] && $typeFile === $v1['file_name'], json_encode($v1));
    $v1Xml = document_manual_read_xml($storage->template((string)$v1['file_name']));
    $paragraphs = document_template_paragraph_text($v1Xml);
    $keys = [];
    foreach ($paragraphs as $text) $keys = array_merge($keys, document_template_keys($text));
    $sortedKeys = $keys; sort($sortedKeys); $want = DOCUMENT_SUMMONS_FIELDS; sort($want);
    sm_check('a) v1 placeholders are exactly the 13 Summons keys, once each', $sortedKeys === $want, json_encode($keys));
    sm_check('a) v1 passes the template check (registered keys, required present, no stray braces)',
        document_template_check($storage->template((string)$v1['file_name']), DOCUMENT_SUMMONS_FIELDS, DOCUMENT_SUMMONS_FIELDS) === []);
    $rewritten = array_values(array_filter($paragraphs, static fn(string $t): bool => str_contains($t, '{{')));
    sm_check('a) no "____" left in the 8 rewritten paragraphs', count($rewritten) === 8
        && array_filter($rewritten, static fn(string $t): bool => str_contains($t, '____')) === [], json_encode($rewritten));
    $origParagraphs = document_template_paragraph_text(document_manual_read_xml($tempSource));
    $changed = [];
    foreach ($origParagraphs as $i => $text) if (($paragraphs[$i] ?? null) !== $text) $changed[] = $i;
    sm_check('a) only paragraphs 5, 6, 7, 9, 11, 13, 16, 18 changed; "Barangay Captain / Punong Barangay" kept',
        $changed === [5, 6, 7, 9, 11, 13, 16, 18] && in_array('Barangay Captain / Punong Barangay', $paragraphs, true), json_encode($changed));
    sm_check('a) body sentence reads as specified (current letter: "in {{venue}}", "in the morning/afternoon" gone)', ($paragraphs[13] ?? '') === 'You are hereby summoned to appear before me in person, together with your witnesses, on the {{hearing_day}} day of {{hearing_month_year}} at {{hearing_time}} in {{venue}}, then and there to answer a complaint made before me, a copy of which is attached hereto, for mediation/conciliation of your dispute with the complainant/s.'
        && ($paragraphs[16] ?? '') === 'Issued this {{issued_day}} day of {{issued_month}}, {{issued_year}}, at Barangay Sampaguita, Tagana-an, Surigao del Norte.'
        && ($paragraphs[18] ?? '') === '{{official_name}}', ($paragraphs[13] ?? '') . ' | ' . ($paragraphs[16] ?? ''));
    sm_check('a) source copies unchanged (temp copy and real summon_letter.docx)',
        hash_file('sha256', $tempSource) === $realShaBefore && hash_file('sha256', $realSource) === $realShaBefore);

    // ---------------------------------------------------------------- fixtures
    $password = 'Summons-pass-2026!';
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $scratchDb->prepare("INSERT INTO barangay_officials (fname,lname,username,role,address,contact,password,position,status) VALUES ('Summons','Staff','summons-staff','staff','Purok 1','+639171110081',?,'Clerk','active')");
    $stmt->bind_param('s', $hash); $stmt->execute(); $staffId = (int)$stmt->insert_id; $stmt->close();
    $householdId = sm_exec($scratchDb, "INSERT INTO households (household_code,address,household_size) VALUES ('SM-" . getmypid() . "','Purok 1',2)");
    $stmt = $scratchDb->prepare("INSERT INTO residents (fname,lname,username,address,contact,password,status,household_id) VALUES ('Juan','Dela Cruz','summons-resident','Purok 1','+639171110082',?,'active',?)");
    $stmt->bind_param('si', $hash, $householdId); $stmt->execute(); $filerId = (int)$stmt->insert_id; $stmt->close();
    $stmt = $scratchDb->prepare("INSERT INTO residents (fname,lname,username,address,contact,password,status,household_id) VALUES ('Pedro','Santos','summons-pedro','Purok 3, Live Street','+639171110083',?,'active',?)");
    $stmt->bind_param('si', $hash, $householdId); $stmt->execute(); $pedroResident = (int)$stmt->insert_id; $stmt->close();
    $scratchDb->query("UPDATE households SET head_resident_id={$filerId} WHERE id={$householdId}");
    $noiseId = (int)sm_value($scratchDb, "SELECT id FROM complaint_types WHERE type_code='noise_disturbance'");
    $noiseName = (string)sm_value($scratchDb, "SELECT type_name FROM complaint_types WHERE id={$noiseId}");
    $mkComplaint = static function (string $ref, ?string $caseNo) use ($scratchDb, $filerId, $noiseId, $noiseName): int {
        $case = $caseNo === null ? 'NULL' : "'{$caseNo}'";
        return sm_exec($scratchDb, "INSERT INTO complaints (resident_id,reference_no,case_no,resident_name,category,complaint_type_id,location_text,description,date_filed,status)
            VALUES ({$filerId},'{$ref}',{$case},'Juan Dela Cruz','{$noiseName}',{$noiseId},'Purok 1','Loud karaoke every night.','Nov 10, 2026','investigating')");
    };
    $c1 = $mkComplaint('SM-REF-1', 'BC-2026-0901');
    $c2 = $mkComplaint('SM-REF-2', 'BC-2026-0902');
    $party = static function (int $complaintId, string $role, string $name, ?int $residentId, ?string $address, int $sort) use ($scratchDb): int {
        $stmt = $scratchDb->prepare("INSERT INTO complaint_parties (complaint_id,role,resident_id,full_name,address,sort_order,created_by_type) VALUES (?,?,?,?,?,?,'system')");
        $stmt->bind_param('isissi', $complaintId, $role, $residentId, $name, $address, $sort); $stmt->execute(); $id = (int)$stmt->insert_id; $stmt->close();
        return $id;
    };
    $pJuan = $party($c1, 'complainant', 'Juan Dela Cruz', $filerId, null, 0);
    $pPedro = $party($c1, 'respondent', 'Pedro Santos', $pedroResident, 'stale stored address', 1);
    $pMaria = $party($c1, 'respondent', 'Maria Reyes', null, 'Purok 5', 2);
    $pOther = $party($c2, 'respondent', 'Other Person', null, null, 1);
    $party($c2, 'complainant', 'Juan Dela Cruz', $filerId, null, 0);

    $tz = new DateTimeZone('Asia/Manila');
    $future = (new DateTimeImmutable('now', $tz))->modify('+10 days')->setTime(9, 0);
    $past = (new DateTimeImmutable('now', $tz))->modify('-2 days')->setTime(9, 0);
    $hearingRow = static function (int $complaintId, int $no, DateTimeImmutable $start, string $status, array $parties) use ($scratchDb, $staffId): int {
        $s = $start->format('Y-m-d H:i:s'); $e = $start->modify('+1 hour')->format('Y-m-d H:i:s');
        $id = sm_exec($scratchDb, "INSERT INTO complaint_hearings (complaint_id,session_no,starts_at,ends_at,venue,venue_key,presiding_official_id,status,created_by,updated_by)
            VALUES ({$complaintId},{$no},'{$s}','{$e}','Barangay Hall','barangay hall',{$staffId},'{$status}',{$staffId},{$staffId})");
        foreach ($parties as $partyId) sm_exec($scratchDb, "INSERT INTO complaint_hearing_attendance (hearing_id,party_id,status) VALUES ({$id},{$partyId},'not_recorded')");
        return $id;
    };
    $h1 = $hearingRow($c1, 1, $future, 'scheduled', [$pJuan, $pPedro, $pMaria]);
    $hDone = $hearingRow($c1, 2, $past, 'completed', [$pJuan, $pPedro, $pMaria]);
    $hCancel = $hearingRow($c1, 3, $future->modify('+1 day'), 'cancelled', [$pJuan, $pPedro, $pMaria]);
    $hPast = $hearingRow($c1, 4, $past->modify('-1 day'), 'scheduled', [$pJuan, $pPedro, $pMaria]);
    $h2 = $hearingRow($c2, 1, $future->modify('+2 days'), 'scheduled', [$pOther]);
    $pLate = $party($c1, 'respondent', 'Late Comer', null, null, 3); // added after scheduling: no attendance row

    // ---------------------------------------------------------------- server + logins
    if (!mkdir($sessionPath, 0700, true) && !is_dir($sessionPath)) throw new RuntimeException('session directory failed');
    file_put_contents($prepend, "<?php foreach (['GEMINI_API_KEY', 'GEMINI_MODEL', 'HTTPSMS_API_KEY', 'HTTPSMS_FROM_NUMBER'] as \$k) { putenv(\$k . '='); \$_ENV[\$k] = ''; \$_SERVER[\$k] = ''; }\n"
        . "if (!str_starts_with((string)getenv('BARANGAY_DB_NAME'), 'capstone_summons_')) { http_response_code(500); exit; }\n"
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
        if ($base === null) { sm_stop($server); $server = null; }
    }
    if ($base === null) throw new RuntimeException('Endpoint server did not start.');
    $login = static function (string $action, string $username) use ($base, $password): string {
        $r = sm_http($base . '/pages/login.php', 'POST', ['action' => $action, 'username' => $username, 'password' => $password]);
        return $r['status'] === 302 ? $r['cookie'] : '';
    };
    $staff = $login('official-login', 'summons-staff');
    $resident = $login('resident-login', 'summons-resident');
    sm_check('setup: staff and resident logins on the scratch database', $staff !== '' && $resident !== '');
    $api = $base . '/api/hearings/';
    $generate = static fn(int $hearingId, int $partyId, ?string $cookie) => sm_http($api . 'summons_generate.php', 'POST',
        (string)json_encode(['hearing_id' => $hearingId, 'party_id' => $partyId]), $cookie);

    // ---------------------------------------------------------------- b) generate
    $captain = (string)document_setting_get($scratchDb, 'captain_name');
    $auditBefore = (int)sm_value($scratchDb, 'SELECT COUNT(*) FROM audit_log');
    $r = $generate($h1, $pPedro, $staff);
    $doc1 = $r['json']['document'] ?? [];
    sm_check('b) generate for a respondent of an upcoming scheduled session -> 200 with the document',
        $r['status'] === 200 && ($doc1['status'] ?? '') === 'current' && (int)($doc1['party_id'] ?? 0) === $pPedro
        && (int)($doc1['hearing_id'] ?? 0) === $h1 && (int)($doc1['template_version'] ?? 0) === 1
        && ($doc1['view_url'] ?? '') === '../api/hearings/summons_view.php?id=' . (int)($doc1['id'] ?? 0) . '&format=pdf'
        && ($doc1['download_url'] ?? '') === '../api/hearings/summons_view.php?id=' . (int)($doc1['id'] ?? 0) . '&format=docx'
        && array_key_exists('superseded_id', $r['json']) && $r['json']['superseded_id'] === null, $r['body']);
    $row1 = sm_rows($scratchDb, 'SELECT * FROM complaint_documents WHERE id=' . (int)($doc1['id'] ?? 0))[0] ?? [];
    $pdf1 = $complaintsDir . DIRECTORY_SEPARATOR . ($row1['file_uuid'] ?? 'x') . '.pdf';
    $docx1 = $complaintsDir . DIRECTORY_SEPARATOR . ($row1['file_uuid'] ?? 'x') . '.docx';
    sm_check('b) row: complaint, hearing, party, version, generated_by, Manila generated_at; files stored with matching sha256',
        (int)($row1['complaint_id'] ?? 0) === $c1 && (int)$row1['template_version_id'] === (int)$v1['id'] && (int)$row1['generated_by'] === $staffId
        && $row1['generated_at'] === ($doc1['generated_at'] ?? null) && is_file($pdf1) && is_file($docx1)
        && hash_file('sha256', $pdf1) === $row1['pdf_sha256'] && hash_file('sha256', $docx1) === $row1['docx_sha256'] && request_valid_pdf($pdf1));
    sm_check('b) complaints folder created with the deny rule', is_file($complaintsDir . DIRECTORY_SEPARATOR . '.htaccess')
        && str_contains((string)file_get_contents($complaintsDir . DIRECTORY_SEPARATOR . '.htaccess'), 'Require all denied'));
    $pdfText = sm_pdftotext($pdf1);
    $text = preg_replace('/\s+/u', ' ', $pdfText ?? sm_docx_text($docx1)) ?? '';
    $day = summons_ordinal((int)$future->format('j'));
    $expect = ['BC-2026-0901', $noiseName, 'Juan Dela Cruz', 'Respondent/s: Pedro Santos, Maria Reyes and Late Comer', 'Pedro Santos, Purok 3, Live Street',
        "on the {$day} day of " . $future->format('F Y'), '9:00 in the morning in Barangay Hall', 'Hon. ' . $captain, 'Barangay Captain / Punong Barangay'];
    $missing = array_values(array_filter($expect, static fn(string $needle): bool => !str_contains($text, $needle)));
    sm_check('b) ' . ($pdfText !== null ? 'PDF text (pdftotext)' : 'DOCX text') . ': case no., type, names, address, ordinal day, time, venue, Hon. captain; no "{{"',
        $missing === [] && !str_contains($text, '{{') && !str_contains($text, 'Wenchilita') && !str_contains($text, 'morning/afternoon'),
        json_encode($missing) . ' :: ' . mb_substr($text, 0, 400));

    // b) Old letter wording (before the venue blank was added): a fixture copy in test storage, never document_templates/.
    $currentXml = document_manual_read_xml($tempSource);
    $oldXml = preg_replace('#<w:r\b[^>]*><w:rPr><w:sz w:val="24"/></w:rPr><w:t xml:space="preserve"> in _______</w:t></w:r>#', '', $currentXml, -1, $removedRuns);
    $oldLetter = $storage->track($storage->root . DIRECTORY_SEPARATOR . 'old-summon-letter.docx');
    document_apply_build_docx($tempSource, (string)$oldXml, $oldLetter);
    $oldOriginal = document_template_paragraph_text(document_manual_read_xml($oldLetter))[13] ?? '';
    $oldBuilt = summons_build_xml(document_manual_read_xml($oldLetter), DOCUMENT_SUMMONS_FIELDS);
    $oldTemplate = $storage->track($storage->root . DIRECTORY_SEPARATOR . 'old-summons-template.docx');
    document_apply_build_docx($oldLetter, $oldBuilt['xml'], $oldTemplate);
    $oldFilled = $storage->track($storage->root . DIRECTORY_SEPARATOR . 'old-summons-filled.docx');
    $sample = [];
    foreach (DOCUMENT_SUMMONS_FIELDS as $key) $sample[$key] = DOCUMENT_SAMPLE_VALUES[$key] ?? 'SAMPLE';
    document_fill_docx($oldTemplate, DOCUMENT_SUMMONS_FIELDS, $sample, $oldFilled);
    $oldText = preg_replace('/\s+/u', ' ', sm_docx_text($oldFilled)) ?? '';
    sm_check('b) old letter wording (fixture copy): built as "at {{hearing_time}} at {{venue}}", renders "9:00 in the morning at Barangay Hall"',
        $removedRuns === 1 && str_ends_with(explode(', then and there', $oldOriginal)[0], 'in the morning/afternoon')
        && $oldBuilt['wording'] === 'old'
        && str_contains(document_template_paragraph_text($oldBuilt['xml'])[13] ?? '', 'at {{hearing_time}} at {{venue}}, then and there')
        && document_template_check($oldTemplate, DOCUMENT_SUMMONS_FIELDS, DOCUMENT_SUMMONS_FIELDS) === []
        && str_contains($oldText, 'at 9:00 in the morning at Barangay Hall, then and there') && !str_contains($oldText, '{{'),
        $oldOriginal . ' :: ' . mb_substr($oldText, 0, 300));
    $otherProblem = null;
    try { summons_build_xml(str_replace('then and there', 'then there', $currentXml), DOCUMENT_SUMMONS_FIELDS); } catch (Throwable $e) { $otherProblem = $e->getMessage(); }
    sm_check('b) any other body wording -> "Expected paragraph not found: You are hereby summoned ..."',
        is_string($otherProblem) && str_starts_with($otherProblem, 'Expected paragraph not found: You are hereby summoned'), (string)$otherProblem);
    sm_check('b) the real summon_letter.docx is unchanged by the fixture work', hash_file('sha256', $realSource) === $realShaBefore);
    $docxText = sm_docx_text($docx1);
    sm_check('b) DOCX has no placeholder left and the issued date is today (Manila)',
        !str_contains($docxText, '{{') && str_contains($docxText, 'Issued this ' . summons_ordinal((int)hearing_now()->format('j')) . ' day of ' . hearing_now()->format('F') . ', ' . hearing_now()->format('Y') . ', at'));
    $note = sm_rows($scratchDb, "SELECT note, from_status, to_status FROM complaint_updates WHERE complaint_id={$c1} ORDER BY id DESC LIMIT 1")[0] ?? [];
    sm_check('b) timeline note is resident-safe: "Summons issued for session 1.", status unchanged',
        ($note['note'] ?? '') === 'Summons issued for session 1.' && $note['from_status'] === 'investigating' && $note['to_status'] === 'investigating', json_encode($note));
    $audit = sm_rows($scratchDb, "SELECT * FROM audit_log WHERE action='Generated summons' ORDER BY id DESC LIMIT 1")[0] ?? [];
    sm_check('b) audit: "Generated summons", target case no., details {hearing_id, party_id, version} only',
        (int)sm_value($scratchDb, 'SELECT COUNT(*) FROM audit_log') === $auditBefore + 1 && ($audit['target_reference'] ?? '') === 'BC-2026-0901'
        && json_decode((string)($audit['details'] ?? ''), true) === ['hearing_id' => $h1, 'party_id' => $pPedro, 'version' => 1]
        && (int)$audit['official_id'] === $staffId, json_encode($audit));

    // ---------------------------------------------------------------- c) values (pure)
    $ordinals = [];
    foreach ([1, 2, 3, 4, 11, 12, 13, 21, 22, 23, 31] as $n) $ordinals[] = summons_ordinal($n);
    sm_check('c) ordinals 1 2 3 4 11 12 13 21 22 23 31', $ordinals === ['1st', '2nd', '3rd', '4th', '11th', '12th', '13th', '21st', '22nd', '23rd', '31st'], json_encode($ordinals));
    sm_check('c) names: "A", "A and B", "A, B and C"', summons_join_names(['A']) === 'A' && summons_join_names(['A', 'B']) === 'A and B'
        && summons_join_names(['A', 'B', 'C']) === 'A, B and C' && summons_join_names([]) === '');
    $at = static fn(string $hm): DateTimeImmutable => new DateTimeImmutable('2026-11-28 ' . $hm . ':00', $tz);
    sm_check('c) time wording: 9:00 morning, 11:59 morning, 12:00 / 1:30 afternoon',
        summons_time_words($at('09:00')) === '9:00 in the morning' && summons_time_words($at('11:59')) === '11:59 in the morning'
        && summons_time_words($at('12:00')) === '12:00 in the afternoon' && summons_time_words($at('13:30')) === '1:30 in the afternoon');
    $values = summons_values(['starts_at' => '2026-11-03 13:30:00', 'venue' => 'Covered Court'], ['case_no' => 'BC-2026-0001', 'type_name' => 'Other'],
        [['role' => 'complainant', 'full_name' => 'A'], ['role' => 'respondent', 'full_name' => 'B'], ['role' => 'respondent', 'full_name' => 'C'], ['role' => 'respondent', 'full_name' => 'D']],
        ['full_name' => 'B', 'address' => null], 'Erwin Astronomo', new DateTimeImmutable('2026-11-21 08:00:00', $tz));
    sm_check('c) summons_values: all 13 keys; afternoon; 3 respondents joined; no address -> name only; issued 21st November 2026',
        array_keys($values) === DOCUMENT_SUMMONS_FIELDS && $values['hearing_day'] === '3rd' && $values['hearing_month_year'] === 'November 2026'
        && $values['hearing_time'] === '1:30 in the afternoon' && $values['respondent_names'] === 'B, C and D' && $values['respondent_to'] === 'B'
        && $values['issued_day'] === '21st' && $values['issued_month'] === 'November' && $values['issued_year'] === '2026'
        && $values['official_name'] === 'Hon. Erwin Astronomo' && $values['venue'] === 'Covered Court' && $values['case_title'] === 'Other', json_encode($values));
    $withAddress = summons_values(['starts_at' => '2026-11-03 09:00:00', 'venue' => 'X'], ['case_no' => 'C', 'type_name' => 'T'], [],
        ['full_name' => 'Maria Reyes', 'address' => ' Purok 5 '], 'N', new DateTimeImmutable('now', $tz));
    sm_check('c) address appended with ", " when present', $withAddress['respondent_to'] === 'Maria Reyes, Purok 5');
    $scratchDb->query("UPDATE residents SET address='Purok 9, New Street' WHERE id={$pedroResident}");
    $ctx = summons_context($db, $h1, $pPedro, hearing_now());
    sm_check('c) registered respondent uses the live residents.address (not the stored party address)', $ctx['respondent']['address'] === 'Purok 9, New Street', (string)$ctx['respondent']['address']);
    $scratchDb->query("UPDATE residents SET address='Purok 3, Live Street' WHERE id={$pedroResident}");

    // ---------------------------------------------------------------- d) refusals
    $countRows = static fn(): int => (int)sm_value($scratchDb, 'SELECT COUNT(*) FROM complaint_documents');
    $before = $countRows();
    $is = static fn(array $r, int $status, string $message): bool => $r['status'] === $status && ($r['json']['message'] ?? '') === $message && ($r['json']['success'] ?? null) === false;
    sm_check('d) completed session -> 409', $is($generate($hDone, $pPedro, $staff), 409, SUMMONS_MSG_NOT_UPCOMING));
    sm_check('d) cancelled session -> 409', $is($generate($hCancel, $pPedro, $staff), 409, SUMMONS_MSG_NOT_UPCOMING));
    sm_check('d) scheduled session already in the past -> 409', $is($generate($hPast, $pPedro, $staff), 409, SUMMONS_MSG_NOT_UPCOMING));
    sm_check('d) respondent added after scheduling -> 422', $is($generate($h1, $pLate, $staff), 422, SUMMONS_MSG_ADDED_AFTER));
    sm_check('d) complainant party -> 422', $is($generate($h1, $pJuan, $staff), 422, SUMMONS_MSG_NOT_RESPONDENT));
    sm_check('d) party of another complaint -> 422', $is($generate($h1, $pOther, $staff), 422, SUMMONS_MSG_NOT_RESPONDENT));
    sm_check('d) unknown session / party -> 404', $generate(999999, $pPedro, $staff)['status'] === 404 && $generate($h1, 999999, $staff)['status'] === 404);
    sm_check('d) bad body (string id, extra key) -> 422', sm_http($api . 'summons_generate.php', 'POST', '{"hearing_id":"1","party_id":1}', $staff)['status'] === 422
        && sm_http($api . 'summons_generate.php', 'POST', (string)json_encode(['hearing_id' => $h1, 'party_id' => $pPedro, 'x' => 1]), $staff)['status'] === 422);
    $scratchDb->query("UPDATE complaint_parties SET is_active=0 WHERE id={$pMaria}");
    sm_check('d) respondent removed from the complaint -> 422', $is($generate($h1, $pMaria, $staff), 422, SUMMONS_MSG_NOT_RESPONDENT));
    $scratchDb->query("UPDATE complaint_parties SET is_active=1 WHERE id={$pMaria}");
    $scratchDb->query("UPDATE document_settings SET setting_value='' WHERE setting_key='captain_name'");
    sm_check('d) captain_name empty -> 503', $is($generate($h1, $pMaria, $staff), 503, SUMMONS_MSG_SIGNATORY));
    $scratchDb->query("UPDATE document_settings SET setting_value='" . $scratchDb->real_escape_string($captain) . "' WHERE setting_key='captain_name'");
    $scratchDb->query("UPDATE document_types SET status='archived' WHERE code='summons'");
    sm_check('d) Summons type archived -> 503 template unavailable', $is($generate($h1, $pMaria, $staff), 503, SUMMONS_MSG_TEMPLATE));
    $scratchDb->query("UPDATE document_types SET status='active' WHERE code='summons'");
    sm_check('d) refusals wrote no row and no file', $countRows() === $before && count(sm_dir_files($complaintsDir)) === 2);

    // ---------------------------------------------------------------- e) regenerate
    $r2 = $generate($h1, $pPedro, $staff);
    $doc2 = $r2['json']['document'] ?? [];
    $rowsPedro = sm_rows($scratchDb, "SELECT id, status FROM complaint_documents WHERE hearing_id={$h1} AND party_id={$pPedro} ORDER BY id");
    sm_check('e) regenerate -> 200, previous row superseded, one current row',
        $r2['status'] === 200 && (int)($r2['json']['superseded_id'] ?? 0) === (int)$doc1['id']
        && $rowsPedro === [['id' => (string)$doc1['id'], 'status' => 'superseded'], ['id' => (string)$doc2['id'], 'status' => 'current']], $r2['body']);
    $dupe = $scratchDb->query("INSERT INTO complaint_documents (complaint_id,hearing_id,party_id,file_uuid,pdf_sha256,docx_sha256,status,generated_by,generated_at)
        VALUES ({$c1},{$h1},{$pPedro},'00000000-0000-4000-8000-000000000000',REPEAT('a',64),REPEAT('a',64),'current',{$staffId},'2026-11-16 10:00:00')");
    sm_check('e) database refuses a second current row for the same hearing + respondent (uq_cdoc_active)', $dupe === false && $scratchDb->errno === 1062, (string)$scratchDb->errno);
    $view1 = sm_http($api . 'summons_view.php?id=' . (int)$doc1['id'] . '&format=pdf', 'GET', null, $staff);
    $view2 = sm_http($api . 'summons_view.php?id=' . (int)$doc2['id'] . '&format=pdf', 'GET', null, $staff);
    sm_check('e) both copies open (old superseded copy and the new one)',
        $view1['status'] === 200 && str_starts_with($view1['body'], '%PDF-') && $view2['status'] === 200 && str_starts_with($view2['body'], '%PDF-')
        && $view1['body'] !== '' && hash('sha256', $view1['body']) === $row1['pdf_sha256']);
    sm_check('e) timeline has one note per generation', (int)sm_value($scratchDb, "SELECT COUNT(*) FROM complaint_updates WHERE complaint_id={$c1} AND note='Summons issued for session 1.'") === 2);

    // ---------------------------------------------------------------- f) concurrency (two processes, same hearing + party)
    file_put_contents($child, '<?php
$root = ' . var_export($root, true) . ';
require_once $root . "/db.php"; $DB_NAME = $argv[1];
require_once $root . "/api/common.php";
require_once $root . "/api/complaints/_community.php";
require_once $root . "/api/hearings/_summons.php";
try {
    $db = get_db();
    echo "READY\n"; fflush(STDOUT);
    for ($i = 0; $i < 500 && !is_file($argv[2]); $i++) usleep(10000);
    $result = summons_generate($db, ["hearing_id" => (int)$argv[3], "party_id" => (int)$argv[4]], (int)$argv[5], "Race");
    echo "OK " . $result["document"]["id"] . "\n";
} catch (ComplaintProblem $p) { echo "ERROR " . $p->status . " " . $p->getMessage() . "\n"; exit(1); }
catch (Throwable $e) { echo "ERROR 500 " . $e->getMessage() . "\n"; exit(1); }
');
    @unlink($go);
    $spawn = static function () use ($child, $scratch, $go, $h1, $pMaria, $staffId): array {
        $process = proc_open([PHP_BINARY, $child, $scratch, $go, (string)$h1, (string)$pMaria, (string)$staffId],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        return [$process, $pipes];
    };
    $a = $spawn(); $b = $spawn();
    $readyA = trim((string)fgets($a[1][1])); $readyB = trim((string)fgets($b[1][1]));
    touch($go);
    $collect = static function (array $h): array {
        $out = trim((string)stream_get_contents($h[1][1])); $err = trim((string)stream_get_contents($h[1][2]));
        fclose($h[1][1]); fclose($h[1][2]);
        return [proc_close($h[0]), $out, $err];
    };
    [$codeA, $outA, $errA] = $collect($a); [$codeB, $outB, $errB] = $collect($b);
    $outs = [$outA, $outB]; sort($outs);
    sm_check('f) concurrent generate for one hearing + respondent: one succeeds, the other gets 409 busy',
        $readyA === 'READY' && $readyB === 'READY' && (int)($codeA === 0) + (int)($codeB === 0) === 1
        && str_starts_with($outs[1], 'OK ') && $outs[0] === 'ERROR 409 ' . SUMMONS_MSG_BUSY, json_encode([$outA, $errA, $outB, $errB]));
    sm_check('f) exactly one row for that hearing + respondent', (int)sm_value($scratchDb, "SELECT COUNT(*) FROM complaint_documents WHERE hearing_id={$h1} AND party_id={$pMaria}") === 1);

    // ---------------------------------------------------------------- g) view / access
    $headers = strtolower($view2['headers']);
    sm_check('g) PDF: inline, Summons-<case_no>-<respondent>.pdf, no-store, nosniff',
        str_contains($headers, 'content-type: application/pdf') && str_contains($view2['headers'], 'Content-Disposition: inline; filename="Summons-BC-2026-0901-pedro-santos.pdf"')
        && str_contains($headers, 'cache-control: private, no-store') && str_contains($headers, 'x-content-type-options: nosniff'), $view2['headers']);
    $docx = sm_http($api . 'summons_view.php?id=' . (int)$doc2['id'] . '&format=docx', 'GET', null, $staff);
    sm_check('g) DOCX: attachment with the same name, file bytes match the row',
        $docx['status'] === 200 && str_contains($docx['headers'], 'Content-Disposition: attachment; filename="Summons-BC-2026-0901-pedro-santos.docx"')
        && hash('sha256', $docx['body']) === (string)sm_value($scratchDb, 'SELECT docx_sha256 FROM complaint_documents WHERE id=' . (int)$doc2['id']));
    $notFound = static fn(string $query): int => sm_http($api . 'summons_view.php?' . $query, 'GET', null, $staff)['status'];
    sm_check('g) unknown id, bad id, bad format, path-like input -> 404',
        $notFound('id=999999&format=pdf') === 404 && $notFound('id=0&format=pdf') === 404 && $notFound('id=abc') === 404
        && $notFound('id=' . (int)$doc2['id'] . '&format=exe') === 404 && $notFound('id=..%2F..%2Fdb.php&format=pdf') === 404
        && $notFound('id=' . (int)$doc2['id'] . '%2F..&format=pdf') === 404);
    $moved = $complaintsDir . DIRECTORY_SEPARATOR . $row1['file_uuid'] . '.pdf';
    rename($moved, $moved . '.bak');
    sm_check('g) a row whose file is missing -> 404 (path only from the DB row)', $notFound('id=' . (int)$doc1['id'] . '&format=pdf') === 404);
    rename($moved . '.bak', $moved);
    $residentCalls = [
        sm_http($api . 'summons_view.php?id=' . (int)$doc2['id'] . '&format=pdf', 'GET', null, $resident)['status'],
        sm_http($api . 'summons_list.php?hearing_id=' . $h1, 'GET', null, $resident)['status'],
        $generate($h1, $pPedro, $resident)['status'],
    ];
    $anonCalls = [
        sm_http($api . 'summons_view.php?id=' . (int)$doc2['id'] . '&format=pdf')['status'],
        sm_http($api . 'summons_list.php?hearing_id=' . $h1)['status'],
        $generate($h1, $pPedro, null)['status'],
    ];
    sm_check('g) resident session: view, list, generate -> 401', $residentCalls === [401, 401, 401], json_encode($residentCalls));
    sm_check('g) logged out: view, list, generate -> 401', $anonCalls === [401, 401, 401], json_encode($anonCalls));
    $residentList = sm_http($base . '/api/complaints/list.php', 'GET', null, $resident);
    sm_check('g) resident complaints list: no summons file, link or id anywhere',
        $residentList['status'] === 200 && !preg_match('/summons_view|file_uuid|complaint_documents|\.pdf/i', $residentList['body'])
        && !str_contains($residentList['body'], (string)$row1['file_uuid']), mb_substr($residentList['body'], 0, 300));
    $list = sm_http($api . 'summons_list.php?hearing_id=' . $h1, 'GET', null, $staff);
    $byParty = array_column($list['json']['respondents'] ?? [], null, 'party_id');
    sm_check('g) summons_list: Pedro issued (1 superseded), Maria issued, Late Comer listed but not allowed',
        $list['status'] === 200 && ($list['json']['can_issue'] ?? false) === true && ($list['json']['hearing']['upcoming'] ?? false) === true
        && (int)($byParty[$pPedro]['current']['id'] ?? 0) === (int)$doc2['id'] && ($byParty[$pPedro]['superseded_count'] ?? 0) === 1
        && ($byParty[$pPedro]['can_generate'] ?? false) === true && ($byParty[$pMaria]['current'] ?? null) !== null
        && ($byParty[$pLate]['on_session'] ?? true) === false && ($byParty[$pLate]['can_generate'] ?? true) === false
        && ($byParty[$pLate]['reason'] ?? '') === SUMMONS_MSG_ADDED_AFTER && !isset($byParty[$pJuan]), $list['body']);
    $listDone = sm_http($api . 'summons_list.php?hearing_id=' . $hDone, 'GET', null, $staff);
    sm_check('g) summons_list of a completed session: not allowed, server reason given',
        $listDone['status'] === 200 && ($listDone['json']['can_issue'] ?? true) === false && ($listDone['json']['blocker'] ?? '') === SUMMONS_MSG_NOT_UPCOMING
        && array_filter($listDone['json']['respondents'] ?? [], static fn(array $r): bool => $r['can_generate'] === true) === []);
    $detail = sm_http($api . 'for_complaint.php?complaint_id=' . $c1, 'GET', null, $staff);
    $sessions = array_column($detail['json']['sessions'] ?? [], null, 'id');
    sm_check('g) for_complaint: "Summons x of y" counts on scheduled sessions only',
        ($sessions[$h1]['summons'] ?? null) === ['issued' => 2, 'total' => 2] && !isset($sessions[$hDone]['summons'])
        && ($sessions[$hPast]['summons'] ?? null) === ['issued' => 0, 'total' => 2], json_encode(array_map(static fn($s) => $s['summons'] ?? null, $sessions)));

    // ---------------------------------------------------------------- h) audience
    $requestable = sm_http($base . '/api/documents/requestable_types.php', 'GET', null, $resident);
    $fieldsResident = sm_http($base . '/api/documents/type_fields.php', 'GET', null, $resident);
    $fieldsStaff = sm_http($base . '/api/documents/type_fields.php', 'GET', null, $staff);
    $names = static fn(array $r): array => array_column($r['json']['types'] ?? [], 'name');
    sm_check('h) requestable_types (resident) and type_fields (resident and official) never list Summons',
        $requestable['status'] === 200 && count($names($requestable)) === 5 && !in_array('Summons', $names($requestable), true)
        && !in_array('Summons', $names($fieldsResident), true) && !in_array('Summons', $names($fieldsStaff), true) && count($names($fieldsStaff)) === 5);
    $create = sm_http($base . '/api/requests/create.php', 'POST', (string)json_encode(['document_type' => 'Summons', 'purpose' => 'Test', 'copies' => 1]), $resident);
    sm_check('h) resident request create with "Summons" -> 422', $create['status'] === 422 && ($create['json']['message'] ?? '') === 'This document is not available yet.', $create['body']);
    $scratchDb->query("INSERT INTO document_prices (document_type, unit_price) VALUES ('Summons', 0.00)");
    $priceId = (int)$scratchDb->insert_id;
    $pricesResident = sm_http($base . '/api/document_prices/list.php', 'GET', null, $resident);
    $pricesStaff = sm_http($base . '/api/document_prices/list.php', 'GET', null, $staff);
    $priceUpdate = sm_http($base . '/api/document_prices/update.php', 'POST', (string)json_encode(['id' => $priceId, 'unit_price' => 5]), $staff);
    sm_check('h) document prices: an internal type\'s price is never listed (resident or official) nor editable (404)',
        !in_array('Summons', array_column($pricesResident['json']['prices'] ?? [], 'document_type'), true)
        && !in_array('Summons', array_column($pricesStaff['json']['prices'] ?? [], 'document_type'), true)
        && count($pricesStaff['json']['prices'] ?? []) === 5 && $priceUpdate['status'] === 404
        && (string)sm_value($scratchDb, "SELECT unit_price FROM document_prices WHERE id={$priceId}") === '0.00', $pricesStaff['body']);
    $scratchDb->query("DELETE FROM document_prices WHERE id={$priceId}");
    $contextProblem = null;
    try { document_registry_context($db, 'Summons'); } catch (RequestProblem $e) { $contextProblem = $e; }
    sm_check('h) request generation refuses the internal type (registry context 422)', $contextProblem !== null && $contextProblem->status === 422
        && $contextProblem->getMessage() === 'Document type is unavailable.');
    sm_check('h) document_registry_requestable(Summons) is null; resident types still requestable',
        document_registry_requestable($db, 'Summons') === null && document_registry_requestable($db, 'Barangay Clearance') !== null);
    $typesList = sm_http($base . '/api/documents/types_list.php', 'GET', null, $staff);
    $summonsListed = array_values(array_filter($typesList['json']['types'] ?? [], static fn(array $t): bool => $t['name'] === 'Summons'))[0] ?? [];
    sm_check('h) Document Templates types_list shows Summons with audience internal and its active v1',
        ($summonsListed['audience'] ?? '') === 'internal' && (int)($summonsListed['active_version_id'] ?? 0) === (int)$v1['id']
        && ($typesList['json']['types'][0]['audience'] ?? '') === 'resident', json_encode($summonsListed));
    $testRender = sm_http($base . '/api/documents/template_test_render.php', 'POST', (string)json_encode(['version_id' => (int)$v1['id']]), $staff);
    sm_check('h) Test render of the Summons version works', $testRender['status'] === 200 && ($testRender['json']['success'] ?? false) === true, $testRender['body']);

    // ---------------------------------------------------------------- i) failure cleanup
    $workspaces = static fn(): array => glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'barangay-summons-*') ?: [];
    $workspacesBefore = $workspaces();
    $filesBefore = sm_dir_files($complaintsDir); $rowsBefore = $countRows(); $notesBefore = (int)sm_value($scratchDb, 'SELECT COUNT(*) FROM complaint_updates');
    $oldPath = getenv('LIBREOFFICE_PATH');
    putenv('LIBREOFFICE_PATH=C:/capstone-missing/soffice.exe');
    $failure = sm_problem(static fn() => summons_generate($db, ['hearing_id' => $h2, 'party_id' => $pOther], $staffId, 'Summons Staff'));
    putenv($oldPath === false ? 'LIBREOFFICE_PATH' : 'LIBREOFFICE_PATH=' . $oldPath);
    sm_check('i) LibreOffice missing -> 503, no row, no file, no timeline note, no temp workspace left',
        $failure !== null && $failure->status === 503 && $failure->getMessage() === SUMMONS_MSG_FAILED && $countRows() === $rowsBefore
        && sm_dir_files($complaintsDir) === $filesBefore && (int)sm_value($scratchDb, 'SELECT COUNT(*) FROM complaint_updates') === $notesBefore
        && $workspaces() === $workspacesBefore, json_encode([$failure?->status, $failure?->getMessage()]));
    $failAfterPublish = sm_problem(static fn() => summons_generate($db, ['hearing_id' => $h2, 'party_id' => $pOther], $staffId, 'Summons Staff',
        static function (string $docx, string $dir, string $op): string { return $dir . '/nothing.pdf'; }));
    sm_check('i) conversion without a valid PDF -> 503, nothing stored', $failAfterPublish !== null && $failAfterPublish->status === 503
        && $countRows() === $rowsBefore && sm_dir_files($complaintsDir) === $filesBefore);
    $lock = sm_value($scratchDb, "SELECT IS_FREE_LOCK('summons_{$h2}_{$pOther}')");
    sm_check('i) the generation lock is released after a failure', (int)$lock === 1);
    $ok = $generate($h2, $pOther, $staff);
    sm_check('i) the same respondent generates fine afterwards', $ok['status'] === 200, $ok['body']);

    // ---------------------------------------------------------------- j) Phase 9: venue article, activation wording guard
    sm_check('j) summons_venue: "The Covered Court" -> "Covered Court"; "the  plaza " -> "plaza"; "Barangay Hall", "Theater Hall", "The" unchanged',
        summons_venue('The Covered Court') === 'Covered Court' && summons_venue('  the  plaza ') === 'plaza' && summons_venue('THE Gym') === 'Gym'
        && summons_venue('Barangay Hall') === 'Barangay Hall' && summons_venue('Theater Hall') === 'Theater Hall' && summons_venue('The') === 'The');
    $venueValues = static fn(string $venue): array => summons_values(['starts_at' => '2026-11-03 09:00:00', 'venue' => $venue], ['case_no' => 'C', 'type_name' => 'T'], [],
        ['full_name' => 'X', 'address' => null], 'N', new DateTimeImmutable('2026-11-01 08:00:00', $tz));
    sm_check('j) with the active wording "at the {{venue}}": "at the Covered Court", "at the Barangay Hall", "at the Theater Hall"',
        'at the ' . $venueValues('The Covered Court')['venue'] === 'at the Covered Court'
        && 'at the ' . $venueValues('Barangay Hall')['venue'] === 'at the Barangay Hall'
        && 'at the ' . $venueValues('Theater Hall')['venue'] === 'at the Theater Hall');
    $venueFilled = $storage->track($storage->root . DIRECTORY_SEPARATOR . 'venue-filled.docx');
    document_fill_docx($storage->template((string)$v1['file_name']), DOCUMENT_SUMMONS_FIELDS, $venueValues('The Covered Court'), $venueFilled);
    sm_check('j) filled document carries the venue without its leading "The"', str_contains(sm_docx_text($venueFilled), 'Covered Court, then and there')
        && !str_contains(sm_docx_text($venueFilled), 'The Covered Court'));

    $goodParagraphs = document_template_paragraph_text($v1Xml);
    $variant = static function (string $from, string $to) use ($goodParagraphs): array {
        return array_map(static fn(string $t): string => str_replace($from, $to, $t), $goodParagraphs);
    };
    $rule = static fn(array $paragraphs): array => document_summons_wording_problems($paragraphs, DOCUMENT_SUMMONS_FIELDS);
    $probs = [
        'good' => $rule($goodParagraphs),
        'morning' => $rule($variant('{{hearing_time}} in', '{{hearing_time}} in the morning/afternoon in')),
        'blank' => $rule($variant('Issued this', 'Issued ____ this')),
        'double' => $rule($variant('then and there', 'then  and there')),
        'twice' => $rule($variant('For: {{case_title}}', 'For: {{case_title}} {{venue}}')),
        'missing' => $rule($variant('{{case_no}}', 'BC')),
        'unknown' => $rule($variant('{{case_no}}', '{{case_no}} {{resident_name}}')),
        'nosentence' => $rule($variant('You are hereby summoned', 'You are summoned')),
    ];
    sm_check('j) wording rule: v1 passes; morning/afternoon, "____", double space in the sentence, a key twice, a key missing, an unknown key, no sentence -> refused',
        $probs['good'] === [] && str_contains(implode('|', $probs['morning']), 'morning/afternoon') && str_contains(implode('|', $probs['blank']), '____')
        && str_contains(implode('|', $probs['double']), 'two spaces') && str_contains(implode('|', $probs['twice']), 'more than once: venue')
        && str_contains(implode('|', $probs['missing']), 'missing placeholders: case_no') && str_contains(implode('|', $probs['unknown']), 'unknown placeholders: resident_name')
        && str_contains(implode('|', $probs['nosentence']), 'summons sentence'), json_encode($probs));

    // Activation over HTTP: fixture versions (checked + tested rows, as the UI would leave them) in test storage only.
    $summonsTypeId = (int)$v1['document_type_id'];
    $addVersion = static function (int $no, string $xml) use ($scratchDb, $storage, $v1, $summonsTypeId): int {
        $file = 'tpl_' . $summonsTypeId . '_v' . $no . '_' . bin2hex(random_bytes(4)) . '.docx';
        $path = $storage->track($storage->template($file));
        document_apply_build_docx($storage->template((string)$v1['file_name']), $xml, $path);
        $sha = hash_file('sha256', $path); $size = filesize($path);
        $stmt = $scratchDb->prepare("INSERT INTO document_template_versions (document_type_id, version_no, file_name, original_name, sha256, size_bytes, status, notes, check_report, check_ok, test_ok, tested_sha256, tested_at)
            VALUES (?, ?, ?, 'fixture.docx', ?, ?, 'draft', 'P9 fixture', '{\"errors\":[]}', 1, 1, ?, NOW())");
        $stmt->bind_param('iissis', $summonsTypeId, $no, $file, $sha, $size, $sha); $stmt->execute(); $id = (int)$stmt->insert_id; $stmt->close();
        return $id;
    };
    $activate = static fn(int $versionId, int $expected) => sm_http($base . '/api/documents/template_activate.php', 'POST',
        (string)json_encode(['version_id' => $versionId, 'expected_active_version_id' => $expected]), $staff);
    $activeId = static fn(): int => (int)sm_value($scratchDb, "SELECT v.id FROM document_template_versions v WHERE v.document_type_id={$summonsTypeId} AND v.status='active'");
    $badMorning = $addVersion(2, str_replace('then and there', 'in the morning/afternoon, then and there', $v1Xml));
    $badDouble = $addVersion(3, str_replace('then and there', 'then  and there', $v1Xml));
    $good = $addVersion(4, $v1Xml);
    $r1 = $activate($badMorning, (int)$v1['id']);
    $r2 = $activate($badDouble, (int)$v1['id']);
    sm_check('j) activation refuses a Summons version with "morning/afternoon" (422, clear message); v1 stays active',
        $r1['status'] === 422 && str_contains((string)($r1['json']['message'] ?? ''), 'This Summons template is not ready: the text still contains "morning/afternoon"')
        && $activeId() === (int)$v1['id'], $r1['body']);
    sm_check('j) activation refuses a Summons version with two spaces in the summons sentence (422)',
        $r2['status'] === 422 && str_contains((string)($r2['json']['message'] ?? ''), 'two spaces in a row') && $activeId() === (int)$v1['id'], $r2['body']);
    $r3 = $activate($good, (int)$v1['id']);
    $r4 = $activate((int)$v1['id'], $good);
    sm_check('j) a clean Summons version activates (200); re-activating the retired v1 also passes the rule',
        $r3['status'] === 200 && $r4['status'] === 200 && $activeId() === (int)$v1['id'], $r3['body'] . ' | ' . $r4['body']);

    // ---------------------------------------------------------------- real database untouched
    $after = [];
    foreach (array_keys($realCounts) as $table) $after[$table] = (int)sm_value($admin, "SELECT COUNT(*) FROM `{$realDatabase}`.`{$table}`");
    sm_check('real database row counts unchanged (document_types, versions, complaints, audit_log)', $after === $realCounts, json_encode([$realCounts, $after]));
    sm_check('real summon_letter.docx unchanged (sha256 before = after)', hash_file('sha256', $realSource) === $realShaBefore);
    echo "summon_letter.docx sha256: {$realShaBefore}\n";
    $exitCode = $failed === 0 ? 0 : 1;
} catch (Throwable $e) {
    sm_check('suite ran without an unexpected error', false, get_class($e) . ': ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
    $exitCode = 1;
} finally {
    $cleanup();
}
$total = $passed + $failed;
echo "{$passed}/{$total} passed\n";
exit($exitCode);
