<?php
/**
 * BarangayLink - Unique contact numbers + password recovery suite.
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 * See the LICENSE file in the project root for the full text.
 *
 * Phase P2: write-path uniqueness on residents/officials create + update, over
 * HTTP (php -S) on a scratch database built from database/database.sql plus
 * migration 20261121_contact_unique.sql (applied twice). The race cases force
 * the real duplicate-key path: an observer transaction holds an uncommitted row
 * with the number, so the endpoint's pre-check misses it and its INSERT/UPDATE
 * waits on the unique key until the observer commits.
 * Phase P5 extends this suite with the recovery cases.
 * No AI, no SMS provider (keys blanked). Everything it creates is dropped with
 * the scratch database / temp files. Never writes to the real database.
 *
 *   php project-support/tests/contact-recovery/run.php
 */

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
foreach (['GEMINI_API_KEY', 'GEMINI_MODEL', 'HTTPSMS_API_KEY', 'HTTPSMS_FROM_NUMBER', 'HTTPSMS_SIM_SLOT', 'PASSWORD_RESET_HINT_KEY'] as $blocked) {
    putenv($blocked . '='); $_ENV[$blocked] = ''; $_SERVER[$blocked] = '';
}
$root = dirname(__DIR__, 3);
require_once $root . '/project-support/tests/_storage.php';
$storage = new TestStorage($root);
require_once $root . '/api/common.php';
require_once $root . '/api/documents/_registry.php';
require_once $root . '/api/auth/_recovery.php';   // recovery_fake_hint() for expected values
$storage->assertActive();
mysqli_report(MYSQLI_REPORT_OFF);

$prefix = 'capstone_contact_';
$scratch = $prefix . getmypid() . '_' . random_int(1000, 9999);
$realDatabase = $DB_NAME;
$passed = 0; $failed = 0;
$admin = null; $scratchDb = null; $observer = null; $server = null;
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR;
$sessionPath = $tmp . 'contact-sessions-' . getmypid();
$prepend = $tmp . 'contact-prepend-' . getmypid() . '.php';
$child = $tmp . 'contact-child-' . getmypid() . '.php';
$crafter = $tmp . 'contact-craft-' . getmypid() . '.php';
$serverLog = $tmp . 'contact-server-' . getmypid();
$mysqlBin = 'mysql';

function cr_check(string $name, bool $ok, string $detail = ''): void {
    global $passed, $failed;
    if ($ok) { $passed++; echo "PASS {$name}\n"; return; }
    $failed++; echo "FAIL {$name}" . ($detail !== '' ? ' :: ' . mb_substr($detail, 0, 600) : '') . "\n";
}
function cr_value(mysqli $db, string $sql, string $types = '', array $values = []): mixed {
    $stmt = $db->prepare($sql);
    if (!$stmt) throw new RuntimeException('prepare failed: ' . $db->error);
    if ($types !== '') $stmt->bind_param($types, ...$values);
    if (!$stmt->execute()) throw new RuntimeException('execute failed: ' . $stmt->error);
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_row() : null;
    $stmt->close();
    return $row[0] ?? null;
}
function cr_rows(mysqli $db, string $sql): array { $r = $db->query($sql); return $r ? $r->fetch_all(MYSQLI_ASSOC) : []; }
function cr_multi(mysqli $db, string $sql): void {
    $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql) ?? $sql;   // database.sql starts with a UTF-8 BOM
    if (!$db->multi_query($sql)) throw new RuntimeException('SQL failed: ' . $db->error);
    do { if ($r = $db->store_result()) $r->free(); } while ($db->more_results() && $db->next_result());
    if ($db->errno) throw new RuntimeException('SQL failed: ' . $db->error);
}
/** Runs a .sql file with the mysql client (the migration uses DELIMITER). */
function cr_mysql_file(string $database, string $file): array {
    global $mysqlBin;
    $proc = proc_open([$mysqlBin, '-uroot', '--default-character-set=utf8mb4', $database],
        [0 => ['file', $file, 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) return [1, 'mysql client did not start'];
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($proc), $out];
}
function cr_http(string $url, string $method = 'GET', array|string|null $body = null, ?string $cookie = null): array {
    $ch = curl_init($url); $headers = [];
    if ($cookie !== null && $cookie !== '') $headers[] = 'Cookie: ' . $cookie;
    if (is_string($body)) $headers[] = 'Content-Type: application/json';
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_POSTFIELDS => $body, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 120]);
    $raw = (string)curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $size = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    $head = substr($raw, 0, $size);
    $bodyOut = substr($raw, $size);
    $json = json_decode($bodyOut, true);
    return ['status' => $status, 'headers' => $head, 'body' => $bodyOut, 'json' => is_array($json) ? $json : []];
}
function cr_stop($process): void {
    if (!is_resource($process)) return;
    $state = proc_get_status($process);
    if ($state['running']) {
        if (PHP_OS_FAMILY === 'Windows') @exec('taskkill /PID ' . (int)$state['pid'] . ' /T /F 2>NUL');
        proc_terminate($process);
    }
    proc_close($process);
}
/** Starts a child process that POSTs one request and prints {status, body}. */
function cr_spawn_post(string $url, array $body, string $cookie): array {
    global $child;
    $env = ['CR_URL' => $url, 'CR_BODY' => (string)json_encode($body), 'CR_COOKIE' => $cookie,
        'SystemRoot' => getenv('SystemRoot') ?: 'C:\\Windows', 'PATH' => getenv('PATH') ?: '', 'TEMP' => sys_get_temp_dir(), 'TMP' => sys_get_temp_dir()];
    $proc = proc_open([PHP_BINARY, $child], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if (!is_resource($proc)) throw new RuntimeException('child did not start');
    fclose($pipes[0]);
    return [$proc, $pipes];
}
function cr_finish_post(array $spawned): array {
    [$proc, $pipes] = $spawned;
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);
    $decoded = json_decode((string)$out, true);
    return is_array($decoded) ? $decoded : ['status' => 0, 'body' => trim($out . $err)];
}
/**
 * Waits until the endpoint's INSERT/UPDATE on $table in $database is blocked:
 * the statement is still in the processlist on two polls 300 ms apart. (MySQL
 * 8.4 does not report this duplicate-key wait as LOCK WAIT in innodb_trx; the
 * statement simply stays in state "update" until the holder commits.)
 */
function cr_wait_blocked_write(mysqli $admin, string $database, string $table, float $seconds = 15.0): bool {
    $deadline = microtime(true) + $seconds;
    $sql = "SELECT COUNT(*) FROM information_schema.processlist WHERE db = ? AND (info LIKE CONCAT('INSERT INTO ', ?, '%') OR info LIKE CONCAT('UPDATE ', ?, ' %'))";
    $firstSeen = null;
    while (microtime(true) < $deadline) {
        $busy = (int)cr_value($admin, $sql, 'sss', [$database, $table, $table]) > 0;
        if (!$busy) { $firstSeen = null; }
        elseif ($firstSeen === null) { $firstSeen = microtime(true); }
        elseif (microtime(true) - $firstSeen >= 0.3) return true;
        usleep(20000);
    }
    return false;
}
/** Text that must never reach a client (SQL keywords are matched case-sensitively). */
function cr_leaks(string $body): array {
    $found = [];
    foreach (['Duplicate entry', 'uq_', 'contact_key', 'SQLSTATE', 'mysqli'] as $needle) {
        if (stripos($body, $needle) !== false) $found[] = $needle;
    }
    foreach (['INSERT ', 'UPDATE ', 'SELECT ', ' WHERE '] as $needle) {
        if (str_contains($body, $needle)) $found[] = trim($needle);
    }
    return $found;
}

$cleanup = static function () use (&$admin, &$scratchDb, &$observer, &$server, $scratch, $prefix, $sessionPath, $prepend, $child, $crafter, $serverLog): void {
    cr_stop($server); $server = null;
    if (is_dir($sessionPath)) foreach (glob($sessionPath . DIRECTORY_SEPARATOR . 'sess_*') ?: [] as $file) @unlink($file);
    @rmdir($sessionPath);
    foreach ([$prepend, $child, $crafter, $serverLog . '.out', $serverLog . '.err'] as $file) @unlink($file);
    if ($observer instanceof mysqli) { @$observer->rollback(); @$observer->close(); $observer = null; }
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
    foreach (['residents', 'barangay_officials', 'households', 'audit_log'] as $table) {
        $realCounts[$table] = (int)cr_value($admin, "SELECT COUNT(*) FROM `{$realDatabase}`.`{$table}`");
    }
    if (!$admin->query("CREATE DATABASE `{$scratch}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) throw new RuntimeException($admin->error);
    $scratchDb = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $scratch);
    $scratchDb->set_charset('utf8mb4');
    cr_multi($scratchDb, (string)file_get_contents($root . '/database/database.sql'));
    $migration = $root . '/project-support/migrations/20261121_contact_unique.sql';
    [$m1, $o1] = cr_mysql_file($scratch, $migration);
    [$m2, $o2] = cr_mysql_file($scratch, $migration);
    $keys = (int)cr_value($scratchDb, "SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND index_name IN ('uq_residents_contact_key','uq_officials_contact_key')");
    cr_check('setup: scratch database from database.sql; migration 20261121 applied twice; both contact keys present',
        $m1 === 0 && $m2 === 0 && $keys === 2, trim($o1 . $o2));
    $DB_NAME = $scratch;

    mkdir($sessionPath, 0700, true);
    file_put_contents($child, "<?php\n"
        . "\$ch = curl_init(getenv('CR_URL'));\n"
        . "curl_setopt_array(\$ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => getenv('CR_BODY'), CURLOPT_TIMEOUT => 120,\n"
        . "  CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Cookie: ' . getenv('CR_COOKIE')]]);\n"
        . "\$body = (string)curl_exec(\$ch); \$status = (int)curl_getinfo(\$ch, CURLINFO_RESPONSE_CODE); curl_close(\$ch);\n"
        . "echo json_encode(['status' => \$status, 'body' => \$body]);\n");

    // SMS stub log + failure switch live in TestStorage (tracked for cleanup).
    $smsLog = $storage->track($storage->root . DIRECTORY_SEPARATOR . 'sms.log');
    $smsFail = $storage->track($storage->root . DIRECTORY_SEPARATOR . 'sms_fail');
    file_put_contents($smsLog, '');

    /**
     * (Re)starts php -S with a prepend that: blanks provider keys, sets
     * PASSWORD_RESET_HINT_KEY to $hintKey (env + $_ENV/$_SERVER, so .env cannot
     * replace it), shortens the recovery padding and stubs the reset SMS (one
     * "sent <type>" line, never the number; fails while $smsFail exists).
     */
    $startServer = static function (string $hintKey) use (&$server, $root, $scratch, $storage, $sessionPath, $prepend, $serverLog, $smsLog, $smsFail): string {
        cr_stop($server); $server = null;
        file_put_contents($prepend, "<?php foreach (['GEMINI_API_KEY', 'GEMINI_MODEL', 'HTTPSMS_API_KEY', 'HTTPSMS_FROM_NUMBER', 'HTTPSMS_SIM_SLOT'] as \$k) { putenv(\$k . '='); \$_ENV[\$k] = ''; \$_SERVER[\$k] = ''; }\n"
            . '$cr_key = ' . var_export($hintKey, true) . "; putenv('PASSWORD_RESET_HINT_KEY=' . \$cr_key); \$_ENV['PASSWORD_RESET_HINT_KEY'] = \$cr_key; \$_SERVER['PASSWORD_RESET_HINT_KEY'] = \$cr_key;\n"
            . "if (!str_starts_with((string)getenv('BARANGAY_DB_NAME'), 'capstone_contact_')) { http_response_code(500); exit; }\n"
            . "define('RECOVERY_MIN_RESPONSE_MS', 400);\n"
            . "function recovery_send_reset_sms(string \$to, string \$code): array {\n"
            . "  \$type = str_starts_with(\$to, '+639') ? 'ok' : 'bad-number';\n"
            . '  if (is_file(' . var_export($smsFail, true) . ")) { file_put_contents(" . var_export($smsLog, true) . ", \"fail\\n\", FILE_APPEND); return ['ok' => false, 'status' => 500, 'message' => 'stub failure']; }\n"
            . '  file_put_contents(' . var_export($smsLog, true) . ", \"sent \$type\\n\", FILE_APPEND);\n"
            . "  return ['ok' => true, 'status' => 200, 'message' => ''];\n"
            . "}\n"
            . 'require_once ' . var_export($root . '/db.php', true) . "; \$DB_NAME = getenv('BARANGAY_DB_NAME');");
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $port = random_int(20000, 45000);
            $environment = ['BARANGAY_DB_NAME' => $scratch, 'BARANGAY_STORAGE_ROOT' => $storage->root, 'PASSWORD_RESET_HINT_KEY' => $hintKey,
                'SystemRoot' => getenv('SystemRoot') ?: 'C:\\Windows', 'PATH' => getenv('PATH') ?: '', 'TEMP' => sys_get_temp_dir(), 'TMP' => sys_get_temp_dir()];
            $server = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $sessionPath, '-d', 'auto_prepend_file=' . $prepend, '-S', '127.0.0.1:' . $port, '-t', $root],
                [0 => ['pipe', 'r'], 1 => ['file', $serverLog . '.out', 'a'], 2 => ['file', $serverLog . '.err', 'a']], $serverPipes, $root, $environment);
            if (!is_resource($server)) continue;
            for ($i = 0; $i < 40; $i++) {
                $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
                if (is_resource($socket)) { fclose($socket); return 'http://127.0.0.1:' . $port; }
                usleep(25000);
            }
            cr_stop($server); $server = null;
        }
        throw new RuntimeException('Endpoint server did not start.');
    };
    $hintKey = 'cr-test-hint-key-0123456789abcdefghijklmn';   // 40 characters, test only
    $base = $startServer($hintKey);

    $login = cr_http($base . '/pages/login.php', 'POST', ['action' => 'official-login', 'username' => 'gem', 'password' => 'admin123']);
    preg_match('/^Set-Cookie:\s*([^;\r\n]+)/mi', $login['headers'], $cookieMatch);
    $cookie = $login['status'] === 302 ? (string)($cookieMatch[1] ?? '') : '';
    cr_check('setup: seed admin gem logs in on the scratch database', $cookie !== '', (string)$login['status']);

    $auditMark = (int)cr_value($scratchDb, 'SELECT COALESCE(MAX(id), 0) FROM audit_log');
    $allBodies = [];
    $post = static function (string $path, array $body) use ($base, $cookie, &$allBodies): array {
        $r = cr_http($base . '/api/' . $path, 'POST', (string)json_encode($body), $cookie);
        $allBodies[] = [$path, $r['body']];
        return $r;
    };
    $count = static fn(string $table): int => (int)cr_value($scratchDb, "SELECT COUNT(*) FROM `{$table}`");
    $contactOf = static fn(string $table, int $id): ?string => cr_value($scratchDb, "SELECT contact FROM `{$table}` WHERE id = ?", 'i', [$id]);
    $idOf = static fn(string $table, string $username): int => (int)cr_value($scratchDb, "SELECT id FROM `{$table}` WHERE username = ?", 's', [$username]);

    $residentBody = static fn(string $user, string $contact): array => [
        'fname' => 'Test', 'lname' => 'Resident', 'address' => 'Purok 1, Brgy. Sampaguita', 'contact' => $contact,
        'username' => $user, 'password' => 'Resident-pass-2026', 'household_mode' => 'new'];
    $officialBody = static fn(string $user, string $contact): array => [
        'fname' => 'Test', 'lname' => 'Official', 'address' => 'Purok 1', 'contact' => $contact,
        'username' => $user, 'password' => 'Official-pass-2026', 'position' => 'Kagawad', 'role' => 'staff'];
    $residentUpdate = static fn(int $id, string $user, string $contact): array => [
        'resident_id' => $id, 'fname' => 'Test', 'lname' => 'Resident', 'address' => 'Purok 1, Brgy. Sampaguita', 'contact' => $contact, 'username' => $user];
    $officialUpdate = static fn(int $id, string $user, string $contact): array => [
        'official_id' => $id, 'fname' => 'Test', 'lname' => 'Official', 'address' => 'Purok 1', 'contact' => $contact,
        'username' => $user, 'position' => 'Kagawad', 'role' => 'staff'];

    $kinds = [
        'resident' => ['table' => 'residents', 'create' => 'residents/create.php', 'update' => 'residents/update.php', 'body' => $residentBody, 'upd' => $residentUpdate, 'u' => 'cr_res'],
        'official' => ['table' => 'barangay_officials', 'create' => 'officials/create.php', 'update' => 'officials/update.php', 'body' => $officialBody, 'upd' => $officialUpdate, 'u' => 'cr_off'],
    ];

    // ===================================================================== per table
    foreach ($kinds as $kind => $k) {
        $t = $k['table'];
        $make = $k['body']; $upd = $k['upd'];

        $a = $post($k['create'], $make($k['u'] . '_a', '09171234567'));
        $aId = $idOf($t, $k['u'] . '_a');
        cr_check("{$kind}: create with 09171234567 stores +639171234567",
            $a['status'] === 200 && $aId > 0 && $contactOf($t, $aId) === '+639171234567', $a['body']);

        foreach (['+639171234567', '639171234567', '0917 123 4567'] as $i => $format) {
            $before = $count($t);
            $dup = $post($k['create'], $make($k['u'] . '_dup' . $i, $format));
            cr_check("{$kind}: create with '{$format}' -> 422 duplicate, no row",
                $dup['status'] === 422 && ($dup['json']['message'] ?? '') === CONTACT_DUPLICATE_MESSAGE && $count($t) === $before, $dup['body']);
        }

        foreach (['12345', '08171234567', '02-8123-4567'] as $i => $bad) {
            $before = $count($t);
            $invalid = $post($k['create'], $make($k['u'] . '_bad' . $i, $bad));
            cr_check("{$kind}: create with invalid '{$bad}' -> 422 invalid, no row",
                $invalid['status'] === 422 && ($invalid['json']['message'] ?? '') === CONTACT_INVALID_MESSAGE && $count($t) === $before, $invalid['body']);
        }

        $before = $count($t);
        $blank = $post($k['create'], $make($k['u'] . '_blank', ''));
        cr_check("{$kind}: blank contact keeps the existing 'required' error",
            $blank['status'] === 400 && str_contains((string)($blank['json']['message'] ?? ''), 'required') && $count($t) === $before, $blank['body']);

        $same = $post($k['update'], $upd($aId, $k['u'] . '_a', '+63 917 123 4567'));
        cr_check("{$kind}: update own row keeping the same number (other format) -> 200, stored E.164",
            $same['status'] === 200 && $contactOf($t, $aId) === '+639171234567', $same['body']);

        $b = $post($k['create'], $make($k['u'] . '_b', '09181234567'));
        $bId = $idOf($t, $k['u'] . '_b');
        $moveB = $post($k['update'], $upd($bId, $k['u'] . '_b', '09171234567'));
        cr_check("{$kind}: update row B to row A's number -> 422 duplicate, B unchanged",
            $b['status'] === 200 && $moveB['status'] === 422 && ($moveB['json']['message'] ?? '') === CONTACT_DUPLICATE_MESSAGE
            && $contactOf($t, $bId) === '+639181234567', $b['body'] . $moveB['body']);

        $badUpdate = $post($k['update'], $upd($bId, $k['u'] . '_b', '12345'));
        cr_check("{$kind}: update with an invalid number -> 422 invalid, B unchanged",
            $badUpdate['status'] === 422 && ($badUpdate['json']['message'] ?? '') === CONTACT_INVALID_MESSAGE && $contactOf($t, $bId) === '+639181234567', $badUpdate['body']);

        // Two creates at once (php -S serves them one after the other).
        $p1 = cr_spawn_post($base . '/api/' . $k['create'], $make($k['u'] . '_p1', '09191230001'), $cookie);
        $p2 = cr_spawn_post($base . '/api/' . $k['create'], $make($k['u'] . '_p2', '+639191230001'), $cookie);
        $r1 = cr_finish_post($p1); $r2 = cr_finish_post($p2);
        $allBodies[] = ['parallel', $r1['body']]; $allBodies[] = ['parallel', $r2['body']];
        $statuses = [(int)$r1['status'], (int)$r2['status']]; sort($statuses);
        $holders = (int)cr_value($scratchDb, "SELECT COUNT(*) FROM `{$t}` WHERE contact_key = '+639191230001'");
        cr_check("{$kind}: two parallel creates with one new number -> exactly one 200 and one 422, no 500",
            $statuses === [200, 422] && $holders === 1, json_encode([$r1, $r2]));

        // Forced race: the duplicate is only detected by the unique key.
        $observer = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $scratch);
        $observer->set_charset('utf8mb4');
        $observer->begin_transaction();
        if ($t === 'residents') {
            if (!$scratchDb->query("INSERT INTO households (household_code,address,household_size) VALUES ('CR-RACE-HOLDER','x',1)")) throw new RuntimeException($scratchDb->error);
            $household = (int)$scratchDb->insert_id;
            $obs = $observer->prepare("INSERT INTO residents (fname,lname,username,address,contact,password,household_id) VALUES ('Race','Holder','cr_res_race_holder','x','+639201230002','x',?)");
            $obs->bind_param('i', $household);
        } else {
            $obs = $observer->prepare("INSERT INTO barangay_officials (fname,lname,username,address,contact,password) VALUES ('Race','Holder','cr_off_race_holder','x','+639201230002','x')");
        }
        if (!$obs || !$obs->execute()) throw new RuntimeException('observer insert failed: ' . $observer->error);
        $obs->close();
        $householdsBefore = $count('households');
        $racer = cr_spawn_post($base . '/api/' . $k['create'], $make($k['u'] . '_racer', '09201230002'), $cookie);
        $waited = cr_wait_blocked_write($admin, $scratch, $t);
        $observer->commit();
        $raced = cr_finish_post($racer);
        $observer->close(); $observer = null;
        $allBodies[] = ['race-create', $raced['body']];
        $racedJson = json_decode((string)$raced['body'], true);
        $extra = $t === 'residents' ? ($count('households') === $householdsBefore) : true;
        cr_check("{$kind}: create racing an uncommitted holder waits on the unique key, then 422 duplicate (no 500)"
            . ($t === 'residents' ? '; its new household is rolled back' : ''),
            $waited && (int)$raced['status'] === 422 && ($racedJson['message'] ?? '') === CONTACT_DUPLICATE_MESSAGE
            && $idOf($t, $k['u'] . '_racer') === 0 && $extra, json_encode($raced) . ' waited=' . var_export($waited, true));
    }

    // Forced race on an update: official B moves to a number an uncommitted UPDATE of A is taking.
    $offA = $idOf('barangay_officials', 'cr_off_a');
    $offB = $idOf('barangay_officials', 'cr_off_b');
    $observer = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $scratch);
    $observer->begin_transaction();
    $obs = $observer->prepare("UPDATE barangay_officials SET contact = '+639221230003' WHERE id = ?");
    $obs->bind_param('i', $offA);
    if (!$obs->execute()) throw new RuntimeException('observer update failed: ' . $observer->error);
    $obs->close();
    $racer = cr_spawn_post($base . '/api/officials/update.php', $officialUpdate($offB, 'cr_off_b', '09221230003'), $cookie);
    $waited = cr_wait_blocked_write($admin, $scratch, 'barangay_officials');
    $observer->commit();
    $raced = cr_finish_post($racer);
    $observer->close(); $observer = null;
    $allBodies[] = ['race-update', $raced['body']];
    $racedJson = json_decode((string)$raced['body'], true);
    cr_check('official: update racing an uncommitted holder waits on the unique key, then 422 duplicate; B unchanged',
        $waited && (int)$raced['status'] === 422 && ($racedJson['message'] ?? '') === CONTACT_DUPLICATE_MESSAGE
        && $contactOf('barangay_officials', $offB) === '+639181234567', json_encode($raced) . ' waited=' . var_export($waited, true));

    // ===================================================================== across tables
    $crossR = $post('residents/create.php', $residentBody('cr_cross_res', '09301234567'));
    $crossO = $post('officials/create.php', $officialBody('cr_cross_off', '+639301234567'));
    cr_check('resident and official may share one number (both 200)',
        $crossR['status'] === 200 && $crossO['status'] === 200
        && $contactOf('residents', $idOf('residents', 'cr_cross_res')) === '+639301234567'
        && $contactOf('barangay_officials', $idOf('barangay_officials', 'cr_cross_off')) === '+639301234567', $crossR['body'] . $crossO['body']);

    $usernameDup = $post('officials/create.php', $officialBody('cr_off_a', '09311234567'));
    cr_check('a duplicate username keeps its own message (not mistaken for a contact duplicate)',
        $usernameDup['status'] === 400 && ($usernameDup['json']['message'] ?? '') === 'Official username already exists.', $usernameDup['body']);

    // ===================================================================== helpers directly
    $helperDb = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $scratch);
    $tableRejected = false;
    try { contact_conflict_exists($helperDb, 'residents; DROP TABLE x', '+639171234567', 0); } catch (RuntimeException $e) { $tableRejected = true; }
    cr_check('contact_conflict_exists rejects a table outside the allow-list', $tableRejected);
    cr_check('contact_conflict_exists finds a holder and ignores the excluded row',
        contact_conflict_exists($helperDb, 'residents', '+639171234567', 0) === true
        && contact_conflict_exists($helperDb, 'residents', '+639171234567', $idOf('residents', 'cr_res_a')) === false);
    $userDupStmt = $helperDb->prepare("INSERT INTO barangay_officials (fname,lname,username,address,contact,password) VALUES ('x','x','cr_off_a','x','','x')");
    $userDupStmt->execute();
    $contactDupStmt = $helperDb->prepare("INSERT INTO barangay_officials (fname,lname,username,address,contact,password) VALUES ('x','x','cr_helper_unique','x','09181234567','x')");
    $contactDupStmt->execute();
    cr_check('is_contact_key_violation: true for contact_key 1062, false for username 1062',
        (int)$userDupStmt->errno === 1062 && is_contact_key_violation($userDupStmt) === false
        && (int)$contactDupStmt->errno === 1062 && is_contact_key_violation($contactDupStmt) === true,
        $userDupStmt->error . ' | ' . $contactDupStmt->error);
    $userDupStmt->close(); $contactDupStmt->close(); $helperDb->close();

    // ===================================================================== P3: recovery
    file_put_contents($crafter, "<?php\nini_set('session.use_strict_mode', '0');\nsession_save_path((string)getenv('CR_SESS_PATH'));\n"
        . "session_id((string)getenv('CR_SID'));\nsession_start();\n\$_SESSION = json_decode((string)getenv('CR_JSON'), true) ?: [];\nsession_write_close();\necho 'OK';\n");
    /** Writes a session file with $data; returns the cookie for it. */
    $craft = static function (array $data) use ($crafter, $sessionPath): string {
        $sid = bin2hex(random_bytes(16));
        $env = ['CR_SESS_PATH' => $sessionPath, 'CR_SID' => $sid, 'CR_JSON' => (string)json_encode($data),
            'SystemRoot' => getenv('SystemRoot') ?: 'C:\\Windows', 'PATH' => getenv('PATH') ?: '', 'TEMP' => sys_get_temp_dir(), 'TMP' => sys_get_temp_dir()];
        $proc = proc_open([PHP_BINARY, $crafter], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);
        if (trim($out) !== 'OK') throw new RuntimeException('session craft failed: ' . $out);
        return 'PHPSESSID=' . $sid;
    };
    $lastCookie = static function (string $headers): string {
        preg_match_all('/^Set-Cookie:\s*(PHPSESSID=[^;\r\n]+)/mi', $headers, $all);
        return (string)(end($all[1]) ?: '');
    };
    $recBodies = [];
    $auth = static function (string $endpoint, array $body, ?string $cookie = null) use (&$base, &$recBodies, $lastCookie): array {
        $t = microtime(true);
        $r = cr_http($base . '/api/auth/' . $endpoint, 'POST', (string)json_encode($body), $cookie);
        $r['ms'] = (int)round((microtime(true) - $t) * 1000);
        $r['cookie'] = $lastCookie($r['headers']);
        $recBodies[] = [$endpoint, $r['body']];
        return $r;
    };
    $request = static fn(string $type, string $identifier): array => $auth('request_otp.php', ['account_type' => $type, 'identifier' => $identifier]);
    $q = static function (string $sql, string $types = '', array $values = []) use ($scratchDb): void {
        $stmt = $scratchDb->prepare($sql);
        if (!$stmt) throw new RuntimeException('prepare failed: ' . $scratchDb->error);
        if ($types !== '') $stmt->bind_param($types, ...$values);
        if (!$stmt->execute()) throw new RuntimeException('execute failed: ' . $stmt->error);
        $stmt->close();
    };
    $clearIp = static fn() => $q("DELETE FROM password_reset_throttle WHERE scope = 'ip'");
    $clearAccount = static fn() => $q("DELETE FROM password_reset_throttle WHERE scope = 'account'");
    $ageCodes = static fn() => $q('UPDATE otp_codes SET created_at = created_at - INTERVAL 2 MINUTE');
    $fresh = static function () use ($clearIp, $clearAccount, $ageCodes): void { $clearIp(); $clearAccount(); $ageCodes(); };
    $smsLines = static fn(): array => array_values(array_filter(explode("\n", (string)file_get_contents($smsLog)), static fn($l) => $l !== ''));
    $codeCount = static fn(): int => (int)cr_value($scratchDb, 'SELECT COUNT(*) FROM otp_codes');
    $latestCode = static fn(string $type, int $id): array => cr_rows($scratchDb, "SELECT id, code, account_type, account_id FROM otp_codes WHERE account_type = '" . ($type === 'official' ? 'official' : 'resident') . "' AND account_id = " . (int)$id . ' AND used = 0 ORDER BY id DESC LIMIT 1')[0] ?? [];
    $newestCode = static fn(): array => cr_rows($scratchDb, 'SELECT account_type, account_id FROM otp_codes ORDER BY id DESC LIMIT 1')[0] ?? [];
    $pwOf = static fn(string $table, int $id): string => (string)cr_value($scratchDb, "SELECT password FROM `{$table}` WHERE id = ?", 'i', [$id]);

    // Fixtures (direct inserts into the scratch database).
    $oldPass = 'Old-pass-2026';
    $oldHash = password_hash($oldPass, PASSWORD_DEFAULT);
    $q("INSERT INTO households (household_code,address,household_size) VALUES ('CR-REC',?,1)", 's', ['Purok 1']);
    $hh = (int)$scratchDb->insert_id;
    $addResident = static function (string $user, string $contact, string $status) use ($q, $scratchDb, $oldHash, $hh): int {
        $q("INSERT INTO residents (fname,lname,username,address,contact,password,status,household_id) VALUES ('Rec','Resident',?,'Purok 1',?,?,?,?)", 'ssssi', [$user, $contact, $oldHash, $status, $hh]);
        return (int)$scratchDb->insert_id;
    };
    $addOfficial = static function (string $user, string $contact, string $status) use ($q, $scratchDb, $oldHash): int {
        $q("INSERT INTO barangay_officials (fname,lname,username,address,contact,password,position,status) VALUES ('Rec','Official',?,'Purok 1',?,?,'Kagawad',?)", 'ssss', [$user, $contact, $oldHash, $status]);
        return (int)$scratchDb->insert_id;
    };
    $recUser = $addResident('rec_user', '+639401110001', 'active');
    $recInactive = $addResident('rec_inactive', '+639401110002', 'inactive');
    $recNoContact = $addResident('rec_nocontact', '', 'active');
    $recDigits = $addResident('09990001111', '+639170009999', 'active');
    $recShared = $addResident('rec_shared', '+639401110005', 'active');
    $recOffShared = $addOfficial('rec_off_shared', '+639401110005', 'active');
    $recOff = $addOfficial('rec_off', '+639401110006', 'active');

    // --- request_otp outcomes (hint key set) ---
    $hintPattern = '/^\+63 9\*\* \*\*\* \*\d{3}$/';
    $outcomes = [];
    $smsDelta = [];
    $runOutcome = static function (string $label, string $type, string $identifier, bool $prepare = true) use (&$outcomes, &$smsDelta, $request, $fresh, $clearIp, $smsLines): array {
        if ($prepare) $fresh(); else $clearIp();
        $before = count($smsLines());
        $r = $request($type, $identifier);
        $smsDelta[$label] = array_slice($smsLines(), $before);
        $outcomes[$label] = $r;
        return $r;
    };
    $runOutcome('username found', 'resident', 'rec_user');
    $runOutcome('phone found 09', 'resident', '09401110001');
    $runOutcome('phone found +63', 'resident', '+639401110001');
    $runOutcome('phone found 639', 'resident', '639401110001');
    $runOutcome('unknown username', 'resident', 'nobody_here');
    $runOutcome('unknown phone', 'resident', '09401119999');
    $runOutcome('inactive account', 'resident', 'rec_inactive');
    $runOutcome('no-contact account', 'resident', 'rec_nocontact');
    $fresh(); $request('resident', 'rec_user');                // a code was just sent ...
    $runOutcome('cooldown', 'resident', 'rec_user', false);   // ... so this one hits the 60 s cooldown
    // Account throttle: five allowed requests (cooldown aged away each time), the sixth is blocked.
    $fresh();
    for ($i = 1; $i <= 5; $i++) { $clearIp(); $ageCodes(); $request('resident', 'rec_user'); }
    $codesBeforeSixth = $codeCount();
    $clearIp(); $ageCodes();
    $before = count($smsLines());
    $outcomes['account throttled (6th)'] = $request('resident', 'rec_user');
    $smsDelta['account throttled (6th)'] = array_slice($smsLines(), $before);
    $throttledNoCode = $codeCount() === $codesBeforeSixth;
    touch($smsFail);
    $runOutcome('SMS failure', 'resident', 'rec_user');
    @unlink($smsFail);

    $reference = $outcomes['username found'];
    $strip = static function (array $r): string { $j = $r['json']; unset($j['hint']); return (string)json_encode($j); };
    foreach ($outcomes as $label => $r) {
        echo "TIMING key-set {$label}: {$r['ms']} ms\n";
        cr_check("request_otp '{$label}': 200, same body (hint aside), valid hint format, >= 400 ms",
            $r['status'] === 200 && $strip($r) === $strip($reference) && ($r['json']['success'] ?? null) === true
            && is_string($r['json']['hint'] ?? null) && preg_match($hintPattern, (string)$r['json']['hint']) === 1 && $r['ms'] >= 400
            && array_keys($r['json']) === ['success', 'message', 'hint'], $r['status'] . ' ' . $r['body'] . ' ' . $r['ms'] . 'ms');
    }
    cr_check('generic message is the agreed text',
        ($reference['json']['message'] ?? '') === "If an account matches, a reset code was sent to the mobile number on file. Didn't get a code? Contact the barangay office.");
    $eligible = ['username found', 'phone found 09', 'phone found +63', 'phone found 639'];
    $smsOk = true; $smsDetail = [];
    foreach ($smsDelta as $label => $lines) {
        $expected = in_array($label, $eligible, true) ? ['sent ok'] : ($label === 'SMS failure' ? ['fail'] : []);
        if ($lines !== $expected) { $smsOk = false; $smsDetail[] = $label . '=' . json_encode($lines); }
    }
    cr_check('SMS stub called only for eligible accounts (found + number + not throttled + no cooldown)', $smsOk, implode(' ', $smsDetail));
    cr_check('account throttle: the 6th request in 15 min mints no code', $throttledNoCode);
    cr_check('stub log never holds a number', preg_match('/\d{6,}/', (string)file_get_contents($smsLog)) !== 1);

    // Hints.
    cr_check('hint: phone input -> mask of the typed number',
        ($outcomes['phone found 09']['json']['hint'] ?? '') === '+63 9** *** *001'
        && ($outcomes['unknown phone']['json']['hint'] ?? '') === '+63 9** *** *999');
    cr_check('hint: username of an account with a number -> its real mask', ($outcomes['username found']['json']['hint'] ?? '') === '+63 9** *** *001');
    $fake2 = $runOutcome('unknown username (again)', 'resident', 'nobody_here');
    $expectedFake = recovery_fake_hint('resident', 'nobody_here', $hintKey);
    cr_check('hint: unknown username -> stable fake hint (same on two calls, HMAC-derived)',
        ($outcomes['unknown username']['json']['hint'] ?? '') === ($fake2['json']['hint'] ?? 'x') && ($fake2['json']['hint'] ?? '') === $expectedFake,
        ($outcomes['unknown username']['json']['hint'] ?? '') . ' / ' . ($fake2['json']['hint'] ?? ''));
    cr_check('hint: no-contact account gets a fake hint, not an empty one',
        ($outcomes['no-contact account']['json']['hint'] ?? '') === recovery_fake_hint('resident', 'rec_nocontact', $hintKey));

    // Same number in both tables: account_type decides.
    $fresh();
    $request('official', '+639401110005');
    $offRow = $newestCode();
    $fresh();
    $request('resident', '09401110005');
    $resRow = $newestCode();
    cr_check('same number in residents and officials: account_type picks the right account',
        ($offRow['account_type'] ?? '') === 'official' && (int)($offRow['account_id'] ?? 0) === $recOffShared
        && ($resRow['account_type'] ?? '') === 'resident' && (int)($resRow['account_id'] ?? 0) === $recShared, json_encode([$offRow, $resRow]));

    // Digit-only username that is not anyone's number: username fallback.
    $fresh();
    $before = count($smsLines());
    $digits = $request('resident', '09990001111');
    $digitRow = $newestCode();
    cr_check('digit-only username that is not a registered number: username fallback sends to its number',
        $digits['status'] === 200 && ($digitRow['account_type'] ?? '') === 'resident' && (int)($digitRow['account_id'] ?? 0) === $recDigits
        && array_slice($smsLines(), $before) === ['sent ok'], json_encode($digitRow));

    // IP throttle.
    $fresh();
    $ipRuns = [];
    for ($i = 1; $i <= 6; $i++) $ipRuns[] = $request('resident', 'nobody_here');
    $sixth = $ipRuns[5];
    echo "TIMING key-set ip throttled (6th): {$sixth['ms']} ms\n";
    cr_check('IP throttle: 5 requests allowed, the 6th -> 429 generic, still padded',
        array_map(static fn($r) => $r['status'], array_slice($ipRuns, 0, 5)) === [200, 200, 200, 200, 200]
        && $sixth['status'] === 429 && ($sixth['json']['message'] ?? '') === 'Too many requests. Please try again later.' && $sixth['ms'] >= 400, $sixth['body']);

    // Throttle table contents.
    $throttleRows = cr_rows($scratchDb, 'SELECT * FROM password_reset_throttle');
    $columns = array_keys($throttleRows[0] ?? []);
    $rawNeedles = ['127.0.0.1', 'nobody_here', 'rec_user', '09401110001', '+639401110001', '9401110001', '09990001111'];
    $badRows = [];
    foreach ($throttleRows as $row) {
        if (preg_match('/^[0-9a-f]{64}$/', (string)$row['key_hash']) !== 1) $badRows[] = 'hash:' . $row['key_hash'];
        foreach ($row as $value) foreach ($rawNeedles as $needle) if (str_contains((string)$value, $needle)) $badRows[] = 'raw:' . $needle;
    }
    $ipHashes = array_values(array_unique(array_column(array_filter($throttleRows, static fn($r) => $r['scope'] === 'ip'), 'key_hash')));
    cr_check('throttle rows: only id/scope/key_hash/created_at, 64-hex hashes, no raw identifier, number or IP',
        $columns === ['id', 'scope', 'key_hash', 'created_at'] && $badRows === [] && count($throttleRows) > 0, json_encode([$columns, $badRows]));
    cr_check('IP hash is keyed (HMAC with the hint key, not a bare SHA-256)',
        $ipHashes === [hash_hmac('sha256', 'ip|127.0.0.1', $hintKey)] && !in_array(hash('sha256', 'ip|127.0.0.1'), $ipHashes, true), json_encode($ipHashes));

    // --- verify / reset ---
    $fresh();
    $issue = $request('resident', 'rec_user');
    $cookieC = $issue['cookie'];
    $codeC = (string)($latestCode('resident', $recUser)['code'] ?? '');
    $verifyForged = $auth('verify_otp.php', ['account_type' => 'official', 'username' => 'rec_off', 'code' => $codeC], $cookieC);
    $resetForged = $auth('reset_password.php', ['account_type' => 'official', 'username' => 'rec_off', 'code' => $codeC, 'new_password' => 'New-pass-2026'], $cookieC);
    cr_check('verify/reset ignore a forged username/account_type: the session account is reset, the named one is not',
        $cookieC !== '' && $verifyForged['status'] === 200 && $resetForged['status'] === 200
        && password_verify('New-pass-2026', $pwOf('residents', $recUser)) && password_verify($oldPass, $pwOf('barangay_officials', $recOff)),
        $verifyForged['body'] . ' | ' . $resetForged['body']);
    $again = $auth('reset_password.php', ['code' => $codeC, 'new_password' => 'Again-2026'], $cookieC);
    $againNew = $auth('reset_password.php', ['code' => $codeC, 'new_password' => 'Again-2026'], $resetForged['cookie'] !== '' ? $resetForged['cookie'] : $cookieC);
    cr_check('second reset with the same (or the regenerated) cookie -> 400',
        $again['status'] === 400 && $againNew['status'] === 400 && ($again['json']['message'] ?? '') === 'Invalid or expired reset code.'
        && password_verify('New-pass-2026', $pwOf('residents', $recUser)), $again['body'] . ' | ' . $againNew['body']);

    $noSessVerify = $auth('verify_otp.php', ['account_type' => 'resident', 'username' => 'rec_user', 'code' => '123456']);
    $noSessReset = $auth('reset_password.php', ['account_type' => 'resident', 'username' => 'rec_user', 'code' => '123456', 'new_password' => 'X-2026']);
    cr_check('no recovery session -> 400 on verify and reset',
        $noSessVerify['status'] === 400 && ($noSessVerify['json']['message'] ?? '') === 'Invalid or expired code.'
        && $noSessReset['status'] === 400 && ($noSessReset['json']['message'] ?? '') === 'Invalid or expired reset code.', $noSessVerify['body'] . $noSessReset['body']);

    $fresh();
    $request('resident', 'rec_user');
    $liveCode = (string)($latestCode('resident', $recUser)['code'] ?? '');
    $expired = $craft(['pw_reset' => ['account_type' => 'resident', 'account_id' => $recUser, 'expires' => time() - 10]]);
    $expiredVerify = $auth('verify_otp.php', ['code' => $liveCode], $expired);
    $expiredReset = $auth('reset_password.php', ['code' => $liveCode, 'new_password' => 'Expired-2026'], $expired);
    $validCraft = $craft(['pw_reset' => ['account_type' => 'resident', 'account_id' => $recUser, 'expires' => time() + 600]]);
    $validVerify = $auth('verify_otp.php', ['code' => $liveCode], $validCraft);
    cr_check('expired recovery session -> 400 (the same code works in a live session)',
        $expiredVerify['status'] === 400 && $expiredReset['status'] === 400 && $validVerify['status'] === 200
        && password_verify('New-pass-2026', $pwOf('residents', $recUser)), $expiredVerify['body'] . $expiredReset['body'] . $validVerify['body']);

    $zeroCraft = $craft(['pw_reset' => ['account_type' => 'resident', 'account_id' => 0, 'expires' => time() + 600]]);
    $zero = $auth('verify_otp.php', ['code' => $liveCode], $zeroCraft);
    cr_check('session for "no account" (id 0) -> same 400', $zero['status'] === 400 && ($zero['json']['message'] ?? '') === 'Invalid or expired code.', $zero['body']);

    $fresh();
    $offIssue = $request('official', 'rec_off');
    $offCode = (string)($latestCode('official', $recOff)['code'] ?? '');
    $q("UPDATE barangay_officials SET status = 'inactive' WHERE id = ?", 'i', [$recOff]);
    $inactiveVerify = $auth('verify_otp.php', ['code' => $offCode], $offIssue['cookie']);
    $inactiveReset = $auth('reset_password.php', ['code' => $offCode, 'new_password' => 'Inactive-2026'], $offIssue['cookie']);
    $q("UPDATE barangay_officials SET status = 'active' WHERE id = ?", 'i', [$recOff]);
    cr_check('account made inactive after the request -> 400 on verify and reset, password unchanged',
        $offCode !== '' && $inactiveVerify['status'] === 400 && $inactiveReset['status'] === 400 && password_verify($oldPass, $pwOf('barangay_officials', $recOff)),
        $inactiveVerify['body'] . $inactiveReset['body']);

    // Two resets at once on separate sessions with the same recovery state.
    $q("UPDATE otp_codes SET used = 1 WHERE account_type = 'official' AND account_id = ?", 'i', [$recOff]);
    $q("INSERT INTO otp_codes (account_type, account_id, code, expires_at) VALUES ('official', ?, '246810', NOW() + INTERVAL 10 MINUTE)", 'i', [$recOff]);
    $state = ['pw_reset' => ['account_type' => 'official', 'account_id' => $recOff, 'expires' => time() + 600]];
    $s1 = $craft($state); $s2 = $craft($state);
    $w1 = cr_spawn_post($base . '/api/auth/reset_password.php', ['code' => '246810', 'new_password' => 'Race-one-2026'], $s1);
    $w2 = cr_spawn_post($base . '/api/auth/reset_password.php', ['code' => '246810', 'new_password' => 'Race-two-2026'], $s2);
    $rr1 = cr_finish_post($w1); $rr2 = cr_finish_post($w2);
    $raceStatuses = [(int)$rr1['status'], (int)$rr2['status']]; sort($raceStatuses);
    $offPw = $pwOf('barangay_officials', $recOff);
    cr_check('two parallel resets on separate sessions -> exactly one success',
        $raceStatuses === [200, 400] && (password_verify('Race-one-2026', $offPw) || password_verify('Race-two-2026', $offPw)), json_encode([$rr1, $rr2]));

    // The P4 login page and payloads: the legacy 'username' alias is gone.
    $page = cr_http($base . '/pages/login.php');
    cr_check('login page carries the new reset field and hint line',
        $page['status'] === 200 && str_contains($page['body'], 'id="resetIdentifier"') && str_contains($page['body'], 'id="resetHint"')
        && !str_contains($page['body'], 'resetUsername'));
    $fresh();
    $legacyOnly = $auth('request_otp.php', ['account_type' => 'resident', 'username' => 'rec_shared']);
    cr_check("request_otp with only the old 'username' key -> 400",
        $legacyOnly['status'] === 400 && ($legacyOnly['json']['message'] ?? '') === 'Account type and username or mobile number are required.', $legacyOnly['body']);

    // Full new flow with a cookie jar, then a real login with the new password.
    $flows = [
        ['resident', 'rec_shared', 'rec_shared', $recShared, 'resident-login', 'resident.php', 'by username'],
        ['resident', '0940 111 0001', 'rec_user', $recUser, 'resident-login', 'resident.php', 'by 09 number'],
        ['official', '+639401110006', 'rec_off', $recOff, 'official-login', 'official.php', 'by +63 number'],
        ['official', 'rec_off_shared', 'rec_off_shared', $recOffShared, 'official-login', 'official.php', 'by username'],
    ];
    foreach ($flows as $n => [$type, $identifier, $user, $id, $action, $landing, $how]) {
        $fresh();
        $step1 = $auth('request_otp.php', ['account_type' => $type, 'identifier' => $identifier]);
        $jar = $step1['cookie'];
        $flowCode = (string)($latestCode($type, $id)['code'] ?? '');
        $step2 = $auth('verify_otp.php', ['code' => $flowCode], $jar);
        $newPass = 'Flow-pass-2026-' . $n;
        $step3 = $auth('reset_password.php', ['code' => $flowCode, 'new_password' => $newPass], $jar);
        $loginRes = cr_http($base . '/pages/login.php', 'POST', ['action' => $action, 'username' => $user, 'password' => $newPass]);
        cr_check("new flow {$type} {$how}: request -> verify -> reset (cookie jar) -> login with the new password",
            $jar !== '' && $flowCode !== '' && $step1['status'] === 200 && $step2['status'] === 200 && $step3['status'] === 200
            && $loginRes['status'] === 302 && str_contains($loginRes['headers'], $landing),
            implode(' | ', [$step1['body'], $step2['body'], $step3['body'], (string)$loginRes['status']]));
    }

    $badInput = $auth('request_otp.php', ['account_type' => 'resident', 'identifier' => "rec\u{202E}user"]);
    $longInput = $auth('request_otp.php', ['account_type' => 'resident', 'identifier' => str_repeat('a', 101)]);
    cr_check('identifier with a bidi control or over 100 chars -> 400 before any lookup',
        $badInput['status'] === 400 && $longInput['status'] === 400
        && ($badInput['json']['message'] ?? '') === 'Account type and username or mobile number are required.', $badInput['body'] . $longInput['body']);

    // --- hint key blank: no hint for anyone, bodies byte-identical ---
    $base = $startServer('');
    $blankRuns = [];
    foreach (['username found' => ['resident', 'rec_shared'], 'phone found' => ['resident', '09401110005'], 'unknown username' => ['resident', 'nobody_here'],
              'unknown phone' => ['resident', '09401119999'], 'no-contact account' => ['resident', 'rec_nocontact'], 'inactive account' => ['resident', 'rec_inactive']] as $label => [$type, $identifier]) {
        $fresh();
        $blankRuns[$label] = $request($type, $identifier);
        echo "TIMING key-blank {$label}: {$blankRuns[$label]['ms']} ms\n";
    }
    $firstBlank = reset($blankRuns);
    $blankSame = true;
    foreach ($blankRuns as $r) if ($r['status'] !== 200 || $r['body'] !== $firstBlank['body'] || $r['ms'] < 400) $blankSame = false;
    cr_check('hint key blank: every outcome -> byte-identical body with hint null, >= 400 ms',
        $blankSame && array_key_exists('hint', $firstBlank['json']) && $firstBlank['json']['hint'] === null, json_encode(array_map(static fn($r) => [$r['status'], $r['body'], $r['ms']], $blankRuns)));
    $blankIp = cr_rows($scratchDb, "SELECT DISTINCT key_hash FROM password_reset_throttle WHERE scope = 'ip'");
    cr_check('hint key blank: throttles still record (IP hash falls back to SHA-256)',
        array_column($blankIp, 'key_hash') === [hash('sha256', 'ip|127.0.0.1')], json_encode($blankIp));

    foreach ($recBodies as [$endpoint, $body]) $allBodies[] = ['auth/' . $endpoint, $body];

    // ===================================================================== leaks
    $leaks = [];
    foreach ($allBodies as [$label, $body]) foreach (cr_leaks((string)$body) as $needle) $leaks[] = $needle . ' in ' . $label . ': ' . mb_substr((string)$body, 0, 160);
    cr_check('no response body carries SQL text or key names', $leaks === [], implode(' || ', $leaks));

    $auditRows = cr_rows($scratchDb, 'SELECT * FROM audit_log WHERE id > ' . $auditMark);
    $auditDigits = [];
    foreach ($auditRows as $row) {
        $text = implode(' ', array_map(static fn($v) => (string)$v, array_diff_key($row, ['created_at' => 1])));
        if (preg_match('/\d{9,}/', $text)) $auditDigits[] = $text;
    }
    cr_check('audit_log rows written by these actions hold no phone number (no 9+ digit runs)',
        count($auditRows) > 0 && $auditDigits === [], implode(' || ', $auditDigits));

    $realAfter = [];
    foreach (array_keys($realCounts) as $table) $realAfter[$table] = (int)cr_value($admin, "SELECT COUNT(*) FROM `{$realDatabase}`.`{$table}`");
    cr_check('the real database row counts are unchanged', $realAfter === $realCounts, json_encode([$realCounts, $realAfter]));

    $exitCode = $failed === 0 ? 0 : 1;
} catch (Throwable $e) {
    cr_check('suite ran without an unexpected error', false, get_class($e) . ': ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
    $exitCode = 1;
} finally {
    $cleanup();
}
echo "TOTAL {$passed} passed, {$failed} failed\n";
exit($exitCode);
