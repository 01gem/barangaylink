<?php
/**
 * BarangayLink - Registry-driven request forms tests (Phase 5B).
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * No AI or SMS: provider keys are blanked. Database tests use scratch
 * databases named capstone_forms_*, built from database/database.sql (never
 * copied from the real database). Template AND generated-document storage is
 * a temp folder (project-support/tests/_storage.php). The test servers get
 * only a scratch database name and the temp storage root. The real database
 * is only read (row counts before/after), never written; the real
 * document_templates/ and generated_documents/ folders are hashed before and
 * after and must not change.
 *
 *   php project-support/tests/document-forms/run.php
 *
 * Also runs frontend.js (node) with the field definitions the scratch
 * endpoint really answered.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
foreach (['GEMINI_API_KEY', 'GEMINI_MODEL', 'HTTPSMS_API_KEY', 'HTTPSMS_FROM_NUMBER'] as $blocked) {
    putenv($blocked . '='); $_ENV[$blocked] = ''; $_SERVER[$blocked] = '';
}
$root = dirname(__DIR__, 3);
require_once $root . '/api/common.php';
require_once $root . '/api/documents/_templates.php';
require_once $root . '/api/documents/_type_create.php';
require_once $root . '/project-support/tests/_storage.php';
mysqli_report(MYSQLI_REPORT_OFF);
$storage = new TestStorage($root);
$storage->assertActive();
$prefix = 'capstone_forms_';
$scratch = $prefix . getmypid() . '_' . random_int(1000, 9999);
$legacyScratch = $scratch . '_legacy';
$realDatabase = $DB_NAME;
$generatedRoot = dirname($storage->previews);
$passed = 0; $failed = 0;
$admin = null; $scratchDb = null; $legacyDb = null; $servers = [];
$tempFiles = [];
$sessionPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'forms-sessions-' . getmypid();
$prepend = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'forms-prepend-' . getmypid() . '.php';
$serverLog = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'forms-server-' . getmypid();

function df_check(string $name, bool $ok, string $detail = ''): void {
    global $passed, $failed;
    if ($ok) { $passed++; echo "PASS {$name}\n"; }
    else { $failed++; echo "FAIL {$name}" . ($detail === '' ? '' : ': ' . $detail) . "\n"; }
}
function df_problem(callable $call): ?RequestProblem {
    try { $call(); } catch (RequestProblem $e) { return $e; }
    return null;
}
function df_is(?RequestProblem $p, string $message): bool {
    return $p !== null && $p->status === 422 && $p->getMessage() === $message;
}
/** sha256 of every file under $directory (recursive), keyed by relative path. */
function df_tree(string $directory): array {
    $rows = [];
    if (!is_dir($directory)) return $rows;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) if ($file->isFile()) $rows[str_replace('\\', '/', substr($file->getPathname(), strlen($directory) + 1))] = hash_file('sha256', $file->getPathname());
    ksort($rows); return $rows;
}
function df_count(mysqli $db, string $sql): int {
    $result = $db->query($sql);
    if (!$result) throw new RuntimeException($db->error);
    return (int)$result->fetch_row()[0];
}
function df_value(mysqli $db, string $sql): mixed {
    $result = $db->query($sql);
    if (!$result) throw new RuntimeException($db->error);
    $row = $result->fetch_row();
    return $row === null ? null : $row[0];
}
function df_rows(mysqli $db, string $sql): array {
    $result = $db->query($sql);
    if (!$result) throw new RuntimeException($db->error);
    return $result->fetch_all(MYSQLI_ASSOC);
}
function df_database_names(mysqli $db, string $prefix): array {
    $stmt = $db->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE CONCAT(?, "%")');
    $stmt->bind_param('s', $prefix); $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    return array_map(static fn(array $row): string => (string)$row['SCHEMA_NAME'], $rows);
}
function df_drop_database(mysqli $db, string $name, string $prefix): void {
    if (str_starts_with($name, $prefix) && preg_match('/^[A-Za-z0-9_]+$/', $name)) $db->query("DROP DATABASE IF EXISTS `$name`");
}
function df_import(mysqli $db, string $sql): void {
    $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql) ?? $sql;
    if (!$db->multi_query($sql)) throw new RuntimeException($db->error);
    do { if ($result = $db->store_result()) $result->free(); } while ($db->more_results() && $db->next_result());
    if ($db->errno) throw new RuntimeException($db->error);
}
function df_http(string $url, string $method = 'GET', array|string|null $body = null, ?string $cookie = null): array {
    $ch = curl_init($url); $headers = [];
    if ($cookie !== null) $headers[] = 'Cookie: ' . $cookie;
    if (is_string($body)) $headers[] = 'Content-Type: application/json';
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_POSTFIELDS => $body, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 180]);
    $raw = (string)curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $size = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    $headersRaw = substr($raw, 0, $size); $cookie = '';
    if (preg_match('/^Set-Cookie:\s*([^;\r\n]+)/mi', $headersRaw, $match)) $cookie = $match[1];
    $json = json_decode(substr($raw, $size), true);
    return ['status' => $status, 'body' => substr($raw, $size), 'json' => is_array($json) ? $json : [], 'cookie' => $cookie];
}
function df_stop_server($process): void {
    if (!is_resource($process)) return;
    $state = proc_get_status($process); if ($state['running']) proc_terminate($process); proc_close($process);
}
function df_uuid(): string {
    $b = random_bytes(16); $b[6] = chr((ord($b[6]) & 0x0f) | 0x40); $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}
/** Visible text of a DOCX body (tags stripped, entities decoded). */
function df_docx_text(string $path): string {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return '';
    $xml = (string)$zip->getFromName('word/document.xml'); $zip->close();
    return html_entity_decode(strip_tags(str_replace('</w:p>', "\n", $xml)), ENT_QUOTES | ENT_XML1, 'UTF-8');
}

$realTemplatesBefore = df_tree($root . '/document_templates');
$realGeneratedBefore = df_tree($root . '/generated_documents');
$realCounts = [];

$cleanup = static function () use (&$admin, &$scratchDb, &$legacyDb, &$servers, &$tempFiles, $prefix, $sessionPath, $prepend, $serverLog, $storage, $generatedRoot): void {
    foreach ($servers as $process) df_stop_server($process);
    $servers = [];
    foreach ($tempFiles as $file) @unlink($file);
    if (is_dir($sessionPath)) foreach (glob($sessionPath . DIRECTORY_SEPARATOR . 'sess_*') ?: [] as $file) @unlink($file);
    @rmdir($sessionPath); @unlink($prepend); @unlink($serverLog . '.out'); @unlink($serverLog . '.err');
    // Everything this suite's servers wrote lives in the temp storage: uploaded versions, previews, generated documents.
    foreach ([$storage->templates, $storage->previews, $generatedRoot] as $dir) {
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $path) if (is_file($path)) $storage->track($path);
    }
    foreach ([$scratchDb, $legacyDb] as $db) if ($db instanceof mysqli) @$db->close();
    $scratchDb = null; $legacyDb = null;
    if ($admin instanceof mysqli) {
        try { foreach (df_database_names($admin, $prefix) as $name) df_drop_database($admin, $name, $prefix); } catch (Throwable $e) {}
    }
};
register_shutdown_function($cleanup);

try {
    df_check('provider key is blank in this process', trim((string)(getenv('GEMINI_API_KEY') ?: '')) === '');
    df_check('template storage resolves to the temp folder', strcasecmp((string)realpath(document_template_root()), (string)realpath($storage->templates)) === 0);
    df_check('generated-document storage resolves to the temp folder', document_storage_override() !== null
        && strcasecmp((string)realpath(document_storage_override() . '/generated_documents'), (string)realpath($generatedRoot)) === 0);

    // ------------------------------------------------ rules: one implementation
    $F = static fn(array $extra = []): array => array_merge(['field_key' => 'x', 'label' => 'Test field', 'source' => 'staff_entered', 'data_type' => 'text',
        'min_value' => null, 'max_value' => null, 'max_length' => null, 'is_required' => 0], $extra);
    df_check('messages: the six rule texts', DOCUMENT_FIELD_MESSAGES === [
        'required' => '{label} is required.', 'int' => '{label} must be a whole number.', 'between' => '{label} must be between {min} and {max}.',
        'min' => '{label} must be at least {min}.', 'max' => '{label} must be at most {max}.', 'max_length' => '{label} must be {max} characters or fewer.']);
    $rules = [
        'required, empty' => [$F(['is_required' => 1]), '', 'Test field is required.'],
        'required, spaces only' => [$F(['is_required' => 1]), "  \t ", 'Test field is required.'],
        'required int, empty' => [$F(['is_required' => 1, 'data_type' => 'int', 'min_value' => 1, 'max_value' => 9]), '', 'Test field is required.'],
        'optional, empty' => [$F(), '', null],
        'optional int, empty' => [$F(['data_type' => 'int', 'min_value' => 1]), '', null],
        'int, letters' => [$F(['data_type' => 'int']), 'abc', 'Test field must be a whole number.'],
        'int, decimal' => [$F(['data_type' => 'int']), '1.5', 'Test field must be a whole number.'],
        'int, exponent' => [$F(['data_type' => 'int']), '1e3', 'Test field must be a whole number.'],
        'int, ten digits' => [$F(['data_type' => 'int']), '1234567890', 'Test field must be a whole number.'],
        'int, valid' => [$F(['data_type' => 'int']), ' 42 ', null],
        'int, negative without limits' => [$F(['data_type' => 'int']), '-3', null],
        'between, below' => [$F(['data_type' => 'int', 'min_value' => 1, 'max_value' => 10]), '0', 'Test field must be between 1 and 10.'],
        'between, above' => [$F(['data_type' => 'int', 'min_value' => 1, 'max_value' => 10]), '11', 'Test field must be between 1 and 10.'],
        'between, lower edge' => [$F(['data_type' => 'int', 'min_value' => 1, 'max_value' => 10]), '1', null],
        'between, upper edge' => [$F(['data_type' => 'int', 'min_value' => 1, 'max_value' => 10]), '10', null],
        'min only, below' => [$F(['data_type' => 'int', 'min_value' => 5]), '4', 'Test field must be at least 5.'],
        'min only, edge' => [$F(['data_type' => 'int', 'min_value' => 5]), '5', null],
        'max only, above' => [$F(['data_type' => 'int', 'max_value' => 9]), '10', 'Test field must be at most 9.'],
        'max only, edge' => [$F(['data_type' => 'int', 'max_value' => 9]), '9', null],
        'int ignores max_length' => [$F(['data_type' => 'int', 'max_length' => 1]), '55', null],
        'text, at max_length' => [$F(['max_length' => 5]), 'abcde', null],
        'text, over max_length' => [$F(['max_length' => 5]), 'abcdef', 'Test field must be 5 characters or fewer.'],
        'text, multibyte at max_length' => [$F(['max_length' => 5]), 'ñañañ', null],
        'text, multibyte over max_length' => [$F(['max_length' => 5]), 'ñañaña', 'Test field must be 5 characters or fewer.'],
        'text, trimmed before counting' => [$F(['max_length' => 5]), '  abcde  ', null],
        'text ignores min/max' => [$F(['min_value' => 9, 'max_value' => 9]), 'abc', null],
        'text, no max_length' => [$F(), str_repeat('x', 1900), null],
        'registry label used as stored' => [$F(['label' => 'Date issued', 'is_required' => 1]), '', 'Date issued is required.'],
    ];
    foreach ($rules as $label => [$field, $value, $expected]) {
        $got = document_field_rule_error($field, $value);
        df_check("rule: {$label}", $got === $expected, (string)json_encode($got));
    }

    // ---------------------------------------------------- scratch databases
    $admin = new mysqli($DB_HOST, $DB_USER, $DB_PASS);
    if ($admin->connect_error) throw new RuntimeException('MySQL connection refused: ' . $admin->connect_error);
    $admin->set_charset('utf8mb4');
    df_check('scratch databases differ from the real database', $scratch !== $realDatabase && $legacyScratch !== $realDatabase && str_starts_with($scratch, $prefix) && str_starts_with($legacyScratch, $prefix));
    foreach (['document_types', 'document_type_fields', 'document_prices', 'document_template_versions', 'audit_log', 'document_requests', 'request_operations'] as $table) {
        $realCounts[$table] = df_count($admin, "SELECT COUNT(*) FROM `{$realDatabase}`.`{$table}`");
    }
    foreach (df_database_names($admin, $prefix) as $old) df_drop_database($admin, $old, $prefix);
    $schema = file_get_contents($root . '/database/database.sql');
    if ($schema === false) throw new RuntimeException('schema unavailable');
    $open = static function (string $name) use ($admin, $DB_HOST, $DB_USER, $DB_PASS, $schema, $prefix): mysqli {
        if (!$admin->query("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) throw new RuntimeException($admin->error);
        $db = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $name); $db->set_charset('utf8mb4');
        if ((string)df_value($db, 'SELECT DATABASE()') !== $name || !str_starts_with($name, $prefix)) throw new RuntimeException('scratch database assertion failed');
        df_import($db, $schema);
        return $db;
    };
    $scratchDb = $open($scratch);
    $legacyDb = $open($legacyScratch);
    df_check('scratch database prefix asserted before writes', str_starts_with((string)df_value($scratchDb, 'SELECT DATABASE()'), $prefix) && str_starts_with((string)df_value($legacyDb, 'SELECT DATABASE()'), $prefix));
    // Legacy install: no registry tables at all.
    $legacyDb->query('SET FOREIGN_KEY_CHECKS=0');
    foreach (['document_template_versions', 'document_type_fields', 'document_types'] as $table) if (!$legacyDb->query("DROP TABLE `{$table}`")) throw new RuntimeException($legacyDb->error);
    $legacyDb->query('SET FOREIGN_KEY_CHECKS=1');
    df_check('legacy scratch database has no registry', document_registry_table_exists($legacyDb) === false && document_registry_table_exists($scratchDb) === true);

    $password = 'Forms-test-2026!';
    $residentPassword = 'Forms-resident-2026!';
    $people = static function (mysqli $db) use ($password, $residentPassword): array {
        $hash = password_hash($password, PASSWORD_DEFAULT); $ids = [];
        foreach (['staff' => '+639171110091', 'admin' => '+639171110092'] as $role => $contact) {
            $stmt = $db->prepare("INSERT INTO barangay_officials (fname,lname,username,role,address,contact,password,position,status) VALUES ('Scratch',?,?,?,'Purok 1',?,?,'Tester','active')");
            $lname = ucfirst($role); $username = 'forms-' . $role;
            $stmt->bind_param('sssss', $lname, $username, $role, $contact, $hash); $stmt->execute(); $ids[$role] = (int)$stmt->insert_id; $stmt->close();
        }
        $db->query("INSERT INTO households (household_code,address,household_size) VALUES ('DF-" . getmypid() . "','Purok 1',1)");
        $householdId = (int)$db->insert_id;
        $residentHash = password_hash($residentPassword, PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO residents (fname,lname,username,address,contact,password,status,household_id,birthdate,civil_status) VALUES ('Scratch','Resident','forms-resident','Purok 1, Brgy. Sampaguita','+639171110093',?,'active',?,'1990-05-20','Married')");
        $stmt->bind_param('si', $residentHash, $householdId); $stmt->execute(); $ids['resident'] = (int)$stmt->insert_id; $stmt->close();
        $db->query("UPDATE households SET head_resident_id={$ids['resident']} WHERE id={$householdId}");
        if ($ids['staff'] <= 0 || $ids['admin'] <= 0 || $ids['resident'] <= 0) throw new RuntimeException('scratch people insert failed');
        return $ids;
    };
    $ids = $people($scratchDb);
    $people($legacyDb);
    $scratchDb->query("UPDATE document_settings SET setting_value='Forms Test Captain' WHERE setting_key='captain_name'");

    // ------------------------------------------- library: staff field rules
    $clearanceId = (int)df_value($scratchDb, "SELECT id FROM document_types WHERE name='Barangay Clearance'");
    $clearanceFields = document_registry_fields($scratchDb, $clearanceId);
    $ok = ['full_name' => 'A', 'age' => '30', 'civil_status' => 'Single', 'address' => 'P1', 'purpose' => 'Employment', 'date_issued' => '2026-11-15'];
    df_check('staff rules: complete seeded payload passes', df_problem(static fn() => document_validate_staff_fields($clearanceFields, $ok)) === null);
    df_check('staff rules: blank purpose -> 422 "Purpose is required."', df_is(df_problem(static fn() => document_validate_staff_fields($clearanceFields, array_merge($ok, ['purpose' => '']))), 'Purpose is required.'));
    df_check('staff rules: blank date -> 422 "Date issued is required."', df_is(df_problem(static fn() => document_validate_staff_fields($clearanceFields, array_merge($ok, ['date_issued' => '  ']))), 'Date issued is required.'));
    df_check('staff rules: a key the modal did not send counts as empty', df_is(df_problem(static fn() => document_validate_staff_fields($clearanceFields, ['purpose' => 'Work'])), 'Date issued is required.'));
    df_check('staff rules: an empty payload fails on the first staff field in registry order', df_is(df_problem(static fn() => document_validate_staff_fields($clearanceFields, [])), 'Purpose is required.'));
    df_check('staff rules: blank resident_record / resident_supplied / system values never fail (fallbacks stay)',
        df_problem(static fn() => document_validate_staff_fields($clearanceFields, ['full_name' => '', 'age' => 'abc', 'civil_status' => '', 'address' => '', 'purpose' => 'Work', 'date_issued' => '2026-11-15'])) === null);
    df_check('staff rules: a non-scalar value counts as empty', df_is(df_problem(static fn() => document_validate_staff_fields($clearanceFields, array_merge($ok, ['purpose' => ['x']]))), 'Purpose is required.'));
    df_check('pre-claim check: unknown request id is left to the run', df_problem(static fn() => request_generation_validate_fields($scratchDb, 999999, [])) === null);
    df_check('pre-claim check: skipped on a legacy database (no registry)', df_problem(static fn() => request_generation_validate_fields($legacyDb, 1, [])) === null);

    // ------------------------------------------------------------- servers
    if (!mkdir($sessionPath, 0700, true) && !is_dir($sessionPath)) throw new RuntimeException('session directory failed');
    file_put_contents($prepend, "<?php foreach (['GEMINI_API_KEY', 'GEMINI_MODEL', 'HTTPSMS_API_KEY', 'HTTPSMS_FROM_NUMBER'] as \$k) { putenv(\$k . '='); \$_ENV[\$k] = ''; \$_SERVER[\$k] = ''; }\n"
        . "if (!str_starts_with((string)getenv('BARANGAY_DB_NAME'), 'capstone_forms_')) { http_response_code(500); exit; }\n"
        . 'require_once ' . var_export($root . '/db.php', true) . "; \$DB_NAME = getenv('BARANGAY_DB_NAME');");
    $startServer = static function (string $database) use (&$servers, $root, $storage, $sessionPath, $prepend, $serverLog): string {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $port = random_int(20000, 45000);
            $environment = ['BARANGAY_DB_NAME' => $database, 'BARANGAY_STORAGE_ROOT' => $storage->root,
                'SystemRoot' => getenv('SystemRoot') ?: 'C:\\Windows', 'PATH' => getenv('PATH') ?: '', 'TEMP' => sys_get_temp_dir(), 'TMP' => sys_get_temp_dir()];
            $process = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $sessionPath, '-d', 'auto_prepend_file=' . $prepend, '-S', '127.0.0.1:' . $port, '-t', $root],
                [0 => ['pipe', 'r'], 1 => ['file', $serverLog . '.out', 'a'], 2 => ['file', $serverLog . '.err', 'a']], $pipes, $root, $environment);
            if (!is_resource($process)) continue;
            for ($i = 0; $i < 40; $i++) {
                $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
                if (is_resource($socket)) { fclose($socket); $servers[] = $process; return 'http://127.0.0.1:' . $port; }
                usleep(25000);
            }
            df_stop_server($process);
        }
        throw new RuntimeException('Endpoint server did not start.');
    };
    $login = static function (string $base, string $action, string $username, string $pass): string {
        $r = df_http($base . '/pages/login.php', 'POST', ['action' => $action, 'username' => $username, 'password' => $pass]);
        return $r['status'] === 302 ? $r['cookie'] : '';
    };
    $base = $startServer($scratch);
    $staff = $login($base, 'official-login', 'forms-staff', $password);
    $adminCookie = $login($base, 'official-login', 'forms-admin', $password);
    $resident = $login($base, 'resident-login', 'forms-resident', $residentPassword);
    df_check('endpoint: staff, admin and resident logins on the scratch database', $staff !== '' && $adminCookie !== '' && $resident !== '');

    // A created type published through the normal lifecycle, one never published, one seeded type archived.
    $post = static fn(string $path, array $body, string $cookie): array => df_http($base . $path, 'POST', (string)json_encode($body, JSON_UNESCAPED_UNICODE), $cookie);
    $r = $post('/api/documents/type_create.php', ['name' => 'Certificate of Cohabitation', 'code' => '', 'fee' => '30.00', 'processing_days' => 1,
        'requirements' => 'Valid ID', 'field_keys' => ['full_name', 'age', 'civil_status', 'address', 'purpose']], $staff);
    $newId = (int)($r['json']['type_id'] ?? 0);
    $v1 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'forms-v1-' . getmypid() . '.docx'; $tempFiles[] = $v1;
    copy($storage->template('barangay_clearance.docx'), $v1);
    $ch = curl_init($base . '/api/documents/template_upload.php');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Cookie: ' . $staff],
        CURLOPT_POSTFIELDS => ['document_type_id' => (string)$newId, 'notes' => 'First version', 'file' => new CURLFile($v1, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'cohabitation.docx')]]);
    $upload = json_decode((string)curl_exec($ch), true) ?: []; curl_close($ch);
    $versionId = (int)($upload['version_id'] ?? 0);
    $rendered = $post('/api/documents/template_test_render.php', ['version_id' => $versionId], $staff);
    $activated = $post('/api/documents/template_activate.php', ['version_id' => $versionId, 'expected_active_version_id' => 0], $staff);
    df_check('setup: created type (full_name, age, civil_status, address, purpose) uploaded, test-rendered and activated',
        $newId > 0 && $versionId > 0 && ($upload['check_ok'] ?? false) === true && $rendered['status'] === 200 && $activated['status'] === 200, $r['body'] . json_encode($upload) . $rendered['body'] . $activated['body']);
    $r = $post('/api/documents/type_create.php', ['name' => 'Certificate of Pending Template', 'code' => '', 'fee' => '0.00', 'processing_days' => 0,
        'requirements' => '', 'field_keys' => ['full_name', 'business_name', 'purpose']], $staff);
    $draftId = (int)($r['json']['type_id'] ?? 0);
    $scratchDb->query("UPDATE document_types SET status='archived' WHERE name='Certificate of Good Moral Character'");
    df_check('setup: one type without an active version, one seeded type archived', $draftId > 0 && $scratchDb->affected_rows === 1);

    // ------------------------------------------------ type_fields.php shape
    $fieldsUrl = $base . '/api/documents/type_fields.php';
    df_check('type_fields: no session -> 401', df_http($fieldsUrl)['status'] === 401);
    df_check('type_fields: POST -> 405', df_http($fieldsUrl, 'POST', '{}', $resident)['status'] === 405 && df_http($fieldsUrl, 'POST', '{}', $staff)['status'] === 405);
    $catalogue = static function (string $key, bool $withSource): array {
        $c = DOCUMENT_FIELD_CATALOGUE[$key]; $row = ['field_key' => $key, 'label' => $c['label']];
        if ($withSource) $row['source'] = $c['source'];
        return $row + ['data_type' => $c['data_type'], 'min_value' => $c['min_value'], 'max_value' => $c['max_value'], 'max_length' => $c['max_length'], 'is_required' => $c['is_required']];
    };
    $rr = df_http($fieldsUrl, 'GET', null, $resident);
    $residentTypes = $rr['json']['types'] ?? [];
    df_check('type_fields resident: 200 with exactly success, registry, types', $rr['status'] === 200 && array_keys($rr['json']) === ['success', 'registry', 'types'] && $rr['json']['registry'] === true, $rr['body']);
    df_check('type_fields resident: only requestable types, in registry order (archived and unpublished types absent)', array_column($residentTypes, 'name') === [
        'Barangay Clearance', 'Certificate of Residency', 'Certificate of Indigency', 'Business Permit Endorsement', 'Certificate of Cohabitation'], json_encode(array_column($residentTypes, 'name')));
    $expectedResident = ['Barangay Clearance' => ['age', 'civil_status'], 'Certificate of Residency' => ['years_of_residency'], 'Certificate of Indigency' => [],
        'Business Permit Endorsement' => ['business_name', 'business_type'], 'Certificate of Cohabitation' => ['age', 'civil_status']];
    foreach ($residentTypes as $type) {
        $name = (string)($type['name'] ?? '');
        $want = array_map(static fn(string $key): array => $catalogue($key, false), $expectedResident[$name] ?? ['?']);
        df_check("type_fields resident shape: {$name} -> name + fields only; resident_supplied definitions exactly",
            array_keys($type) === ['name', 'fields'] && $type['fields'] === $want, json_encode($type));
    }
    $residentFieldKeys = array_merge(...array_map(static fn(array $t): array => array_map('array_keys', $t['fields']), $residentTypes));
    df_check('type_fields resident: every field has exactly field_key, label, data_type, min_value, max_value, max_length, is_required',
        $residentFieldKeys !== [] && array_values(array_unique($residentFieldKeys, SORT_REGULAR)) === [['field_key', 'label', 'data_type', 'min_value', 'max_value', 'max_length', 'is_required']]);
    $leaks = array_values(array_filter(['"source"', 'staff_entered', 'resident_record', 'resident_supplied', '"system"', 'full_name', 'owner_name', 'address', 'purpose', 'date_issued',
        'reference_no', 'official_name', 'official_title', '"id"', '"status"', 'has_active_version', 'archived', '.docx', 'Good Moral', 'Pending Template', 'template_file', '"code"'],
        static fn(string $needle): bool => str_contains($rr['body'], $needle)));
    df_check('type_fields resident: no staff_entered / resident_record / system field, source, id, status, file or hidden type anywhere in the body', $leaks === [], implode(', ', $leaks));

    $ro = df_http($fieldsUrl, 'GET', null, $staff);
    $officialTypes = array_column($ro['json']['types'] ?? [], null, 'name');
    df_check('type_fields official: 200 with exactly success, registry, types; every type listed', $ro['status'] === 200 && array_keys($ro['json']) === ['success', 'registry', 'types'] && $ro['json']['registry'] === true
        && array_keys($officialTypes) === ['Barangay Clearance', 'Certificate of Residency', 'Certificate of Indigency', 'Certificate of Good Moral Character',
            'Business Permit Endorsement', 'Certificate of Cohabitation', 'Certificate of Pending Template'], json_encode(array_keys($officialTypes)));
    foreach (LEGACY_DOCUMENT_MAP as $name => $config) {
        $keys = array_values(array_filter($config['placeholders'], static fn(string $key): bool => DOCUMENT_FIELD_CATALOGUE[$key]['source'] !== 'system'));
        $want = ['name' => $name, 'status' => $name === 'Certificate of Good Moral Character' ? 'archived' : 'active', 'has_active_version' => true,
            'fields' => array_map(static fn(string $key): array => $catalogue($key, true), $keys)];
        df_check("type_fields official shape: {$name} -> non-system fields in registry order, status, has_active_version", ($officialTypes[$name] ?? null) === $want, json_encode($officialTypes[$name] ?? null));
    }
    df_check('type_fields official: created type carries its fields in catalogue order', ($officialTypes['Certificate of Cohabitation'] ?? null) === ['name' => 'Certificate of Cohabitation', 'status' => 'active', 'has_active_version' => true,
        'fields' => array_map(static fn(string $key): array => $catalogue($key, true), ['full_name', 'address', 'age', 'civil_status', 'purpose', 'date_issued'])], json_encode($officialTypes['Certificate of Cohabitation'] ?? null));
    df_check('type_fields official: unpublished type listed with has_active_version false', ($officialTypes['Certificate of Pending Template']['has_active_version'] ?? null) === false
        && array_column($officialTypes['Certificate of Pending Template']['fields'] ?? [], 'field_key') === ['full_name', 'business_name', 'purpose', 'date_issued']);
    df_check('type_fields official: no system field, id or file name', !str_contains($ro['body'], '"system"') && !str_contains($ro['body'], 'reference_no') && !str_contains($ro['body'], 'official_name')
        && !str_contains($ro['body'], '"id"') && !str_contains($ro['body'], '.docx'));
    df_check('type_fields: admin gets the official view too', (df_http($fieldsUrl, 'GET', null, $adminCookie)['json']['types'] ?? null) === ($ro['json']['types'] ?? []));
    df_check('requestable_types (5A) unchanged: only name, fee, processing_days, requirements per row',
        array_keys(df_http($base . '/api/documents/requestable_types.php', 'GET', null, $resident)['json']['types'][0] ?? []) === ['name', 'fee', 'processing_days', 'requirements']);

    // ------------------------------------- frontend (node) on the real responses
    $residentJson = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'forms-resident-' . getmypid() . '.json'; $tempFiles[] = $residentJson;
    $officialJson = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'forms-official-' . getmypid() . '.json'; $tempFiles[] = $officialJson;
    file_put_contents($residentJson, $rr['body']); file_put_contents($officialJson, $ro['body']);
    putenv('FORMS_RESIDENT_JSON=' . $residentJson); putenv('FORMS_OFFICIAL_JSON=' . $officialJson); putenv('PHP_BINARY=' . PHP_BINARY);
    $node = proc_open(['node', $root . '/project-support/tests/document-forms/frontend.js'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $nodePipes, $root);
    $nodeOut = ''; $nodeCode = -1;
    if (is_resource($node)) { fclose($nodePipes[0]); $nodeOut = (string)stream_get_contents($nodePipes[1]) . (string)stream_get_contents($nodePipes[2]); fclose($nodePipes[1]); fclose($nodePipes[2]); $nodeCode = proc_close($node); }
    putenv('FORMS_RESIDENT_JSON'); putenv('FORMS_OFFICIAL_JSON');
    $frontendLine = preg_match('/^FRONTEND (\d+) passed, (\d+) failed$/m', $nodeOut, $fm) ? $fm[0] : '';
    df_check('frontend.js (node): all checks pass, including the goldens rendered from the scratch endpoint responses', $nodeCode === 0 && $frontendLine !== '' && (int)$fm[2] === 0
        && str_contains($nodeOut, 'PASS resident form, type_fields.php response from the scratch database') && str_contains($nodeOut, 'PASS staff modal, type_fields.php response from the scratch database'),
        $frontendLine . ' ' . implode(' | ', array_slice(array_filter(explode("\n", $nodeOut), static fn(string $l): bool => str_starts_with($l, 'FAIL') || str_contains($l, 'Error')), 0, 5)));
    echo "     {$frontendLine}\n";

    // ------------------------------------- create.php whitelist, created type
    $requestUrl = '/api/requests/create.php';
    $stored = static function (string $ref) use ($scratchDb): mixed {
        $raw = df_value($scratchDb, "SELECT request_fields_json FROM document_requests WHERE reference_no='" . $scratchDb->real_escape_string($ref) . "'");
        return $raw === null ? null : json_decode((string)$raw, true); // the JSON column normalises spacing
    };
    $r = $post($requestUrl, ['document_type' => 'Certificate of Cohabitation', 'purpose' => 'Local employment', 'copies' => 1,
        'fields' => ['age' => '30', 'civil_status' => '  Single ', 'business_name' => 'Not mine', 'years_of_residency' => 5, 'hacker' => 'x', 'full_name' => 'Someone Else', 'purpose' => 'override']], $resident);
    $cohabRef = (string)($r['json']['reference_no'] ?? '');
    df_check('create.php: created type keeps only its own resident fields (other catalogue keys, staff keys and unknown keys dropped)',
        $r['status'] === 200 && $stored($cohabRef) === ['age' => 30, 'civil_status' => 'Single'], $r['body'] . ' ' . json_encode($stored($cohabRef)));
    $r = $post($requestUrl, ['document_type' => 'Certificate of Cohabitation', 'purpose' => 'Travel', 'copies' => 1, 'fields' => ['age' => 500, 'civil_status' => str_repeat('x', 150)]], $resident);
    df_check('create.php: created type applies the registry limits (age out of range dropped, text cut to 100)',
        $r['status'] === 200 && $stored((string)($r['json']['reference_no'] ?? '')) === ['civil_status' => str_repeat('x', 100)], $r['body']);
    $r = $post($requestUrl, ['document_type' => 'Certificate of Cohabitation', 'purpose' => 'Travel', 'copies' => 1], $resident);
    df_check('create.php: optional resident fields may be left out (is_required 0)', $r['status'] === 200 && $stored((string)($r['json']['reference_no'] ?? '')) === null, $r['body']);
    $r = $post($requestUrl, ['document_type' => 'Barangay Clearance', 'purpose' => 'Employment', 'copies' => 1, 'fields' => ['age' => 35, 'civil_status' => 'Married', 'business_name' => 'x']], $resident);
    $clearanceRef = (string)($r['json']['reference_no'] ?? '');
    df_check('create.php: seeded type unchanged (own fields stored, others dropped)', $r['status'] === 200 && $stored($clearanceRef) === ['age' => 35, 'civil_status' => 'Married'], $r['body']);
    df_check('create.php: seeded type without fields still accepted', $post($requestUrl, ['document_type' => 'Barangay Clearance', 'purpose' => 'Employment', 'copies' => 1], $resident)['status'] === 200);
    // is_required on a resident field (no shipped row has it; set on the scratch row only).
    $scratchDb->query("UPDATE document_type_fields SET is_required=1 WHERE document_type_id={$newId} AND field_key='age'");
    $requests0 = df_count($scratchDb, 'SELECT COUNT(*) FROM document_requests');
    $r = $post($requestUrl, ['document_type' => 'Certificate of Cohabitation', 'purpose' => 'Travel', 'copies' => 1, 'fields' => ['civil_status' => 'Single']], $resident);
    df_check('create.php: required resident field missing -> 422 "Age is required."', $r['status'] === 422 && ($r['json']['message'] ?? '') === 'Age is required.', $r['body']);
    $r = $post($requestUrl, ['document_type' => 'Certificate of Cohabitation', 'purpose' => 'Travel', 'copies' => 1, 'fields' => ['age' => 'abc']], $resident);
    df_check('create.php: required resident field with an unusable value -> same 422', $r['status'] === 422 && ($r['json']['message'] ?? '') === 'Age is required.');
    $r = $post($requestUrl, ['document_type' => 'Certificate of Cohabitation', 'purpose' => 'Travel', 'copies' => 1, 'fields' => ['age' => 121]], $resident);
    df_check('create.php: required resident field out of range -> same 422', $r['status'] === 422 && ($r['json']['message'] ?? '') === 'Age is required.');
    df_check('create.php: refused requests stored nothing', df_count($scratchDb, 'SELECT COUNT(*) FROM document_requests') === $requests0);
    $scratchDb->query("UPDATE document_types SET status='archived' WHERE id={$newId}");
    $r = $post($requestUrl, ['document_type' => 'Certificate of Cohabitation', 'purpose' => 'Travel', 'copies' => 1], $resident);
    df_check('create.php: archived still answers the 5A message before the required check', $r['status'] === 422 && ($r['json']['message'] ?? '') === 'This document type is no longer available.');
    $scratchDb->query("UPDATE document_types SET status='active' WHERE id={$newId}");
    $r = $post($requestUrl, ['document_type' => 'Certificate of Cohabitation', 'purpose' => 'Travel', 'copies' => 1, 'fields' => ['age' => 44]], $resident);
    df_check('create.php: required resident field supplied -> accepted', $r['status'] === 200 && $stored((string)($r['json']['reference_no'] ?? '')) === ['age' => 44], $r['body']);
    df_check('create.php: seeded type is not affected by another type\'s required flag', $post($requestUrl, ['document_type' => 'Barangay Clearance', 'purpose' => 'Employment', 'copies' => 1], $resident)['status'] === 200);
    $scratchDb->query("UPDATE document_type_fields SET is_required=0 WHERE document_type_id={$newId} AND field_key='age'");

    // ------------------------------------------ generation endpoint: every 422
    $requestId = static fn(string $ref): int => (int)df_value($scratchDb, "SELECT id FROM document_requests WHERE reference_no='" . $scratchDb->real_escape_string($ref) . "'");
    $cohabId = $requestId($cohabRef); $clearanceRequestId = $requestId($clearanceRef);
    $generate = static fn(int $id, array $fields, ?string $cookie, int $version = 1): array => df_http($base . '/api/requests/generate_document.php', 'POST',
        (string)json_encode(['id' => $id, 'fields' => (object)$fields, 'expected_version' => $version, 'operation_id' => df_uuid()], JSON_UNESCAPED_UNICODE), $cookie);
    $modal = ['full_name' => '', 'address' => '', 'age' => '', 'civil_status' => '', 'purpose' => 'Local employment', 'date_issued' => '2026-11-15'];
    df_check('generate: no session -> 401', $generate($cohabId, $modal, null)['status'] === 401);
    df_check('generate: resident session -> 401', $generate($cohabId, $modal, $resident)['status'] === 401);
    $is422 = static fn(array $r, string $message): bool => $r['status'] === 422 && ($r['json']['success'] ?? null) === false && ($r['json']['message'] ?? '') === $message;
    $r = $generate($cohabId, array_merge($modal, ['purpose' => '']), $staff);
    df_check('generate 422: blank purpose -> "Purpose is required."', $is422($r, 'Purpose is required.'), $r['body']);
    $r = $generate($cohabId, array_merge($modal, ['purpose' => '   ']), $staff);
    df_check('generate 422: purpose of spaces only -> "Purpose is required."', $is422($r, 'Purpose is required.'), $r['body']);
    $r = $generate($cohabId, array_merge($modal, ['date_issued' => '']), $staff);
    df_check('generate 422: blank date -> "Date issued is required."', $is422($r, 'Date issued is required.'), $r['body']);
    $r = $generate($cohabId, ['purpose' => 'Local employment'], $staff);
    df_check('generate 422: date_issued not sent -> "Date issued is required."', $is422($r, 'Date issued is required.'), $r['body']);
    $r = $generate($cohabId, [], $staff);
    df_check('generate 422: empty fields -> first staff field in registry order', $is422($r, 'Purpose is required.'), $r['body']);
    $r = $generate($clearanceRequestId, ['full_name' => 'X', 'age' => '35', 'civil_status' => 'Married', 'address' => 'P1', 'purpose' => '', 'date_issued' => '2026-11-15'], $staff);
    df_check('generate 422: seeded type, blank purpose -> "Purpose is required." (accepted 5B change)', $is422($r, 'Purpose is required.'), $r['body']);
    // The catalogue has no int / limited staff field; add scratch-only rows to reach every rule.
    foreach ([['copies_issued', 'Copies issued', 'int', 1, 10, null, 0], ['floor_no', 'Floor number', 'int', 5, null, null, 0], ['page_count', 'Page count', 'int', null, 9, null, 0],
              ['remarks', 'Remarks', 'text', null, null, 10, 0], ['or_number', 'OR number', 'text', null, null, 20, 1]] as $index => [$key, $label, $dataType, $min, $max, $length, $required]) {
        $stmt = $scratchDb->prepare("INSERT INTO document_type_fields (document_type_id, field_key, label, source, data_type, min_value, max_value, max_length, is_required, sort_order) VALUES (?, ?, ?, 'staff_entered', ?, ?, ?, ?, ?, ?)");
        $sort = 50 + $index; $stmt->bind_param('isssiiiii', $newId, $key, $label, $dataType, $min, $max, $length, $required, $sort);
        if (!$stmt->execute()) throw new RuntimeException($stmt->error); $stmt->close();
    }
    $full = array_merge($modal, ['copies_issued' => '2', 'floor_no' => '5', 'page_count' => '9', 'remarks' => 'ok', 'or_number' => 'OR-1']);
    foreach ([
        'int, letters' => [['copies_issued' => 'two'], 'Copies issued must be a whole number.'],
        'int, decimal' => [['copies_issued' => '2.5'], 'Copies issued must be a whole number.'],
        'between, below' => [['copies_issued' => '0'], 'Copies issued must be between 1 and 10.'],
        'between, above' => [['copies_issued' => '11'], 'Copies issued must be between 1 and 10.'],
        'min only' => [['floor_no' => '4'], 'Floor number must be at least 5.'],
        'max only' => [['page_count' => '10'], 'Page count must be at most 9.'],
        'max_length' => [['remarks' => 'eleven char'], 'Remarks must be 10 characters or fewer.'],
        'required text' => [['or_number' => ''], 'OR number is required.'],
        'required text over its length' => [['or_number' => str_repeat('9', 21)], 'OR number must be 20 characters or fewer.'],
    ] as $label => [$change, $message]) {
        $r = $generate($cohabId, array_merge($full, $change), $staff);
        df_check("generate 422: {$label} -> \"{$message}\"", $is422($r, $message), $r['body']);
    }
    $opsAfterRefusals = df_count($scratchDb, 'SELECT COUNT(*) FROM request_operations');
    df_check('generate: no 422 claimed an operation (refused before the claim)', $opsAfterRefusals === 0, (string)$opsAfterRefusals);
    $r = $generate($cohabId, array_merge($full, ['copies_issued' => '', 'floor_no' => '', 'page_count' => '', 'remarks' => '']), $staff, 99);
    df_check('generate: optional staff fields may be blank (passes the rules, then the stale version answers 409)', $r['status'] === 409, $r['body']);
    $r = $generate($cohabId, array_merge($full, ['age' => 'not a number', 'civil_status' => str_repeat('x', 500)]), $staff, 99);
    df_check('generate: rules apply to staff_entered only (resident_supplied values are not validated here)', $r['status'] === 409, $r['body']);
    df_check('generate: existing payload checks still first ("Invalid document field.")', ($x = df_http($base . '/api/requests/generate_document.php', 'POST',
        (string)json_encode(['id' => $cohabId, 'fields' => ['purpose' => 5], 'expected_version' => 1, 'operation_id' => df_uuid()]), $staff))['status'] === 400 && ($x['json']['message'] ?? '') === 'Invalid document field.', $x['body']);
    $scratchDb->query("DELETE FROM document_type_fields WHERE document_type_id={$newId} AND sort_order >= 50");
    $state = df_rows($scratchDb, "SELECT status, row_version, generation_token, generated_document_path FROM document_requests WHERE id IN ({$cohabId}, {$clearanceRequestId})");
    df_check('generate: every refusal left the requests pending, unversioned and without a lease; only the two 409 probes reached the claim',
        array_unique(array_map('json_encode', $state)) === [json_encode(['status' => 'pending', 'row_version' => '1', 'generation_token' => null, 'generated_document_path' => null])]
        && df_count($scratchDb, 'SELECT COUNT(*) FROM request_operations') === 2, json_encode($state) . ' ops=' . df_count($scratchDb, 'SELECT COUNT(*) FROM request_operations'));
    df_check('generate: refusals wrote no file', glob($generatedRoot . DIRECTORY_SEPARATOR . '*.{pdf,docx}', GLOB_BRACE) === []);
    $r = $generate(999999, [], $staff);
    df_check('generate: unknown request id is answered by the run, not by a field rule', $r['status'] !== 200 && $r['status'] !== 422 && !str_contains((string)($r['json']['message'] ?? ''), 'is required'), $r['body']);

    // --------------------------------- generation success: created + seeded type
    $r = $generate($cohabId, $modal, $staff);
    $pdfRel = (string)($r['json']['generated_document_path'] ?? '');
    $pdf = $storage->root . '/' . $pdfRel; $docx = preg_replace('/\.pdf$/', '.docx', $pdf);
    df_check('generate created type: 200, request is Processing', $r['status'] === 200 && ($r['json']['success'] ?? false) === true && preg_match('#^generated_documents/[0-9a-f-]{36}\.pdf$#', $pdfRel) === 1
        && (string)df_value($scratchDb, "SELECT status FROM document_requests WHERE id={$cohabId}") === 'processing', $r['body']);
    df_check('generate created type: PDF and DOCX are in the temp storage, not in the real folder', is_file($pdf) && is_file((string)$docx) && request_valid_pdf($pdf)
        && !is_file($root . '/' . $pdfRel) && !is_file($root . '/' . preg_replace('/\.pdf$/', '.docx', $pdfRel)));
    $text = is_file((string)$docx) ? df_docx_text((string)$docx) : '';
    df_check('generate created type: no unresolved placeholder', $text !== '' && !str_contains($text, '{{') && !str_contains($text, '}}'));
    df_check('generate created type: resident record, stored resident fields, staff fields and system fields are filled',
        str_contains($text, 'Scratch Resident') && str_contains($text, '30') && str_contains($text, 'Single') && str_contains($text, 'Purok 1')
        && str_contains($text, 'Local employment') && str_contains($text, 'November 15, 2026') && str_contains($text, $cohabRef), $text);
    df_check('generate created type: signed "Hon. <captain>" / "Barangay Captain" from the setting', str_contains($text, 'Hon. Forms Test Captain') && str_contains($text, 'Barangay Captain') && !str_contains($text, 'Erwin Astronomo'), $text);
    $r = $generate($clearanceRequestId, ['full_name' => 'Scratch Resident', 'age' => '35', 'civil_status' => 'Married', 'address' => 'Purok 1, Brgy. Sampaguita', 'purpose' => 'Employment', 'date_issued' => '2026-11-15'], $staff);
    $seededDocx = $storage->root . '/' . preg_replace('/\.pdf$/', '.docx', (string)($r['json']['generated_document_path'] ?? 'missing.pdf'));
    $seededText = is_file($seededDocx) ? df_docx_text($seededDocx) : '';
    df_check('generate seeded type: the payload the modal sends still generates (4A-5A behaviour)', $r['status'] === 200 && str_contains($seededText, 'Scratch Resident') && str_contains($seededText, '35')
        && str_contains($seededText, 'Married') && str_contains($seededText, 'November 15, 2026') && str_contains($seededText, 'Hon. Forms Test Captain') && !str_contains($seededText, '{{'), $r['body']);

    // --------------------------------------- template version + file availability
    df_check('generate created type: template_version_id is the version it rendered (the one activated above)',
        (int)df_value($scratchDb, "SELECT template_version_id FROM document_requests WHERE id={$cohabId}") === (int)$versionId);
    $scratchDb->query("UPDATE document_requests SET status='completed' WHERE id={$cohabId}");
    $scratchDb->query("INSERT INTO document_requests (reference_no,resident_id,resident_name,document_type,purpose,date_requested,status,payment_status,generated_document_path)
        SELECT 'FORMS-MISSING',resident_id,resident_name,document_type,purpose,date_requested,'completed','paid','generated_documents/" . df_uuid() . ".pdf' FROM document_requests WHERE id={$cohabId}");
    $missingId = (int)$scratchDb->insert_id;
    $scratchDb->query("INSERT INTO document_requests (reference_no,resident_id,resident_name,document_type,purpose,date_requested,status)
        SELECT 'FORMS-NOPATH',resident_id,resident_name,document_type,purpose,date_requested,'pending' FROM document_requests WHERE id={$cohabId}");
    $noPathId = (int)$scratchDb->insert_id;
    $listed = df_http($base . '/api/requests/list.php', 'GET', null, $staff);
    $byId = []; foreach (($listed['json']['requests'] ?? []) as $row) $byId[(int)$row['id']] = $row;
    df_check('list: file_available true for a completed request whose PDF is in storage', ($byId[$cohabId]['file_available'] ?? null) === true, $listed['body']);
    df_check('list: file_available false when the stored PDF is gone', $missingId > 0 && ($byId[$missingId]['file_available'] ?? null) === false);
    df_check('list: file_available false when nothing was generated', $noPathId > 0 && ($byId[$noPathId]['file_available'] ?? null) === false);
    $mine = df_http($base . '/api/requests/list.php', 'GET', null, $resident);
    df_check('list: residents get no file_available', $mine['status'] === 200 && array_filter($mine['json']['requests'] ?? [], fn($row) => array_key_exists('file_available', $row)) === [], $mine['body']);

    // ----------------------------------------------- legacy: no registry table
    $legacyBase = $startServer($legacyScratch);
    $legacyStaff = $login($legacyBase, 'official-login', 'forms-staff', $password);
    $legacyResident = $login($legacyBase, 'resident-login', 'forms-resident', $residentPassword);
    df_check('legacy: staff and resident logins on the registry-less scratch database', $legacyStaff !== '' && $legacyResident !== '');
    $lr = df_http($legacyBase . '/api/documents/type_fields.php', 'GET', null, $legacyResident);
    $lo = df_http($legacyBase . '/api/documents/type_fields.php', 'GET', null, $legacyStaff);
    df_check('legacy: type_fields answers {registry: false, types: []} to both roles (forms use their built-in lists)',
        $lr['status'] === 200 && $lr['json'] === ['success' => true, 'registry' => false, 'types' => []] && $lo['status'] === 200 && $lo['json'] === $lr['json'], $lr['body'] . $lo['body']);
    $r = df_http($legacyBase . $requestUrl, 'POST', (string)json_encode(['document_type' => 'Barangay Clearance', 'purpose' => 'Employment', 'copies' => 1,
        'fields' => ['age' => 28, 'civil_status' => 'Single', 'business_name' => 'x', 'hacker' => 'y']]), $legacyResident);
    $legacyRef = (string)($r['json']['reference_no'] ?? '');
    df_check('legacy: create.php still uses the legacy whitelist', $r['status'] === 200
        && json_decode((string)df_value($legacyDb, "SELECT request_fields_json FROM document_requests WHERE reference_no='" . $legacyDb->real_escape_string($legacyRef) . "'"), true) === ['age' => 28, 'civil_status' => 'Single'], $r['body']);
    $legacyId = (int)df_value($legacyDb, "SELECT id FROM document_requests WHERE reference_no='" . $legacyDb->real_escape_string($legacyRef) . "'");
    $r = df_http($legacyBase . '/api/requests/generate_document.php', 'POST', (string)json_encode(['id' => $legacyId,
        'fields' => ['full_name' => '', 'age' => '', 'civil_status' => '', 'address' => '', 'purpose' => '', 'date_issued' => ''], 'expected_version' => 1, 'operation_id' => df_uuid()]), $legacyStaff);
    $legacyDocx = $storage->root . '/' . preg_replace('/\.pdf$/', '.docx', (string)($r['json']['generated_document_path'] ?? 'missing.pdf'));
    $legacyText = is_file($legacyDocx) ? df_docx_text($legacyDocx) : '';
    df_check('legacy: generation skips the registry rules and keeps its fallbacks (blank purpose -> request purpose, blank date -> today)',
        $r['status'] === 200 && str_contains($legacyText, 'Scratch Resident') && str_contains($legacyText, 'Employment') && str_contains($legacyText, '28') && str_contains($legacyText, 'Single')
        && str_contains($legacyText, date('F d, Y')) && str_contains($legacyText, 'Hon. Erwin Astronomo') && !str_contains($legacyText, '{{'), $r['body'] . ' ' . $legacyText);
    foreach ($servers as $process) df_stop_server($process);
    $servers = [];
} catch (Throwable $e) {
    df_check('suite completed without an unexpected error', false, get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $generatedByTests = count(glob($generatedRoot . DIRECTORY_SEPARATOR . '*.{pdf,docx}', GLOB_BRACE) ?: []);
    $cleanup();
    df_check('real document_templates/ folder unchanged (sha256 of every file)', df_tree($root . '/document_templates') === $realTemplatesBefore);
    df_check('real generated_documents/ folder unchanged (sha256 of every file)', df_tree($root . '/generated_documents') === $realGeneratedBefore);
    echo "     generated in temp storage and removed: {$generatedByTests} files\n";
    if ($admin instanceof mysqli) {
        foreach ($realCounts as $table => $count) {
            df_check('real row count unchanged ' . $table, df_count($admin, "SELECT COUNT(*) FROM `{$realDatabase}`.`{$table}`") === $count);
        }
        df_check('no scratch database left behind', df_database_names($admin, $prefix) === []);
    }
}
echo "Results: {$passed}/" . ($passed + $failed) . " passed.\n";
exit($failed === 0 ? 0 : 1);
