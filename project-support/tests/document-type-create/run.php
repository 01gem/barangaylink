<?php
/**
 * BarangayLink - Add document type tests (Phase 5A).
 * Copyright (C) 2026 Jun Gem Riege M. Dulduco
 * Licensed under the GNU General Public License v3.0 (or later).
 *
 * No AI or SMS: provider keys are blanked. Database tests use a scratch
 * database named capstone_typecreate_*, built from database/database.sql
 * (never copied from the real database); the 20261115 migration is applied
 * only to that scratch database. Template storage is a temp folder
 * (project-support/tests/_storage.php). The endpoint test server gets only the
 * scratch database name and the temp storage root. The real database is only
 * read (row counts before/after), never written.
 *
 *   php project-support/tests/document-type-create/run.php
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
$prefix = 'capstone_typecreate_';
$scratch = $prefix . getmypid() . '_' . random_int(1000, 9999);
$realDatabase = $DB_NAME;
$templateRoot = $storage->templates;
$migrationPath = $root . '/project-support/migrations/20261115_document_type_create.sql';
$passed = 0; $failed = 0;
$admin = null; $scratchDb = null; $server = null;
$tempFiles = []; $templateFiles = [];
$sessionPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typecreate-sessions-' . getmypid();
$prepend = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typecreate-prepend-' . getmypid() . '.php';
$serverLog = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typecreate-server-' . getmypid();

function tc_check(string $name, bool $ok, string $detail = ''): void {
    global $passed, $failed;
    if ($ok) { $passed++; echo "PASS {$name}\n"; }
    else { $failed++; echo "FAIL {$name}" . ($detail === '' ? '' : ': ' . $detail) . "\n"; }
}
/** Run $call, return the RequestProblem it threw (or null). */
function tc_problem(callable $call): ?RequestProblem {
    try { $call(); } catch (RequestProblem $e) { return $e; }
    return null;
}
function tc_is(?RequestProblem $p, int $status, string $message): bool {
    return $p !== null && $p->status === $status && $p->getMessage() === $message;
}
function tc_snapshot(string $directory): array {
    $rows = [];
    foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) if (is_file($path)) $rows[basename($path)] = hash_file('sha256', $path);
    ksort($rows); return $rows;
}
function tc_count(mysqli $db, string $sql): int {
    $result = $db->query($sql);
    if (!$result) throw new RuntimeException($db->error);
    return (int)$result->fetch_row()[0];
}
function tc_value(mysqli $db, string $sql): mixed {
    $result = $db->query($sql);
    if (!$result) throw new RuntimeException($db->error);
    $row = $result->fetch_row();
    return $row === null ? null : $row[0];
}
function tc_rows(mysqli $db, string $sql): array {
    $result = $db->query($sql);
    if (!$result) throw new RuntimeException($db->error);
    return $result->fetch_all(MYSQLI_ASSOC);
}
function tc_database_names(mysqli $db, string $prefix): array {
    $stmt = $db->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE CONCAT(?, "%")');
    $stmt->bind_param('s', $prefix); $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    return array_map(static fn(array $row): string => (string)$row['SCHEMA_NAME'], $rows);
}
function tc_drop_database(mysqli $db, string $name, string $prefix): void {
    if (str_starts_with($name, $prefix) && preg_match('/^[A-Za-z0-9_]+$/', $name)) $db->query("DROP DATABASE IF EXISTS `$name`");
}
function tc_multi(mysqli $db, string $sql): void {
    $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql) ?? $sql;
    if (!$db->multi_query($sql)) throw new RuntimeException($db->error);
    do { if ($result = $db->store_result()) $result->free(); } while ($db->more_results() && $db->next_result());
    if ($db->errno) throw new RuntimeException($db->error);
}
function tc_import(mysqli $db, string $sql): void {
    tc_multi($db, $sql);
}
function tc_create_table(mysqli $db, string $table): string {
    $row = $db->query("SHOW CREATE TABLE `{$table}`")->fetch_row();
    return preg_replace('/ AUTO_INCREMENT=\d+/', '', (string)$row[1]) ?? '';
}
function tc_http(string $url, string $method = 'GET', array|string|null $body = null, ?string $cookie = null): array {
    $ch = curl_init($url); $headers = [];
    if ($cookie !== null) $headers[] = 'Cookie: ' . $cookie;
    if (is_string($body)) $headers[] = 'Content-Type: application/json';
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_POSTFIELDS => $body, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 120]);
    $raw = (string)curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $size = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    $headersRaw = substr($raw, 0, $size); $cookie = '';
    if (preg_match('/^Set-Cookie:\s*([^;\r\n]+)/mi', $headersRaw, $match)) $cookie = $match[1];
    $json = json_decode(substr($raw, $size), true);
    return ['status' => $status, 'body' => substr($raw, $size), 'json' => is_array($json) ? $json : [], 'cookie' => $cookie];
}
function tc_stop_server($process): void {
    if (!is_resource($process)) return;
    $state = proc_get_status($process); if ($state['running']) proc_terminate($process); proc_close($process);
}
function tc_input(array $extra = []): array {
    return array_merge(['name' => 'Certificate of Solo Parent', 'code' => '', 'fee' => '25.00', 'processing_days' => 2,
        'requirements' => "Valid ID\nBarangay clearance", 'field_keys' => ['full_name', 'address', 'purpose']], $extra);
}

$templateSnapshot = tc_snapshot($templateRoot);
$realCounts = [];

$cleanup = static function () use (&$admin, &$scratchDb, &$server, &$tempFiles, &$templateFiles, $templateRoot, $prefix, $templateSnapshot, $sessionPath, $prepend, $serverLog, $storage): void {
    tc_stop_server($server); $server = null;
    foreach ($tempFiles as $file) @unlink($file);
    if (is_dir($sessionPath)) foreach (glob($sessionPath . DIRECTORY_SEPARATOR . 'sess_*') ?: [] as $file) @unlink($file);
    @rmdir($sessionPath); @unlink($prepend); @unlink($serverLog . '.out'); @unlink($serverLog . '.err');
    if ($scratchDb instanceof mysqli) {
        $result = @$scratchDb->query('SELECT file_name FROM document_template_versions');
        if ($result) foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) $templateFiles[] = (string)$row['file_name'];
    }
    foreach (array_unique($templateFiles) as $file) {
        if (!isset($templateSnapshot[$file]) && preg_match('/^[a-z0-9_]+\.docx$/', $file)) @unlink($templateRoot . DIRECTORY_SEPARATOR . $file);
    }
    foreach (glob($storage->previews . DIRECTORY_SEPARATOR . '*.pdf') ?: [] as $pdf) {
        if (preg_match('/^[a-f0-9]{32}\.pdf$/', basename($pdf))) $storage->track($pdf);
    }
    if ($admin instanceof mysqli) {
        try { foreach (tc_database_names($admin, $prefix) as $name) tc_drop_database($admin, $name, $prefix); } catch (Throwable $e) {}
    }
};
register_shutdown_function($cleanup);

try {
    tc_check('provider key is blank in this process', trim((string)(getenv('GEMINI_API_KEY') ?: '')) === '');
    tc_check('template storage resolves to the temp folder', strcasecmp((string)realpath(document_template_root()), (string)realpath($storage->templates)) === 0);

    // ------------------------------------------------------ code derivation
    $derive = [
        'Certificate of Good Moral Character' => 'certificate_of_good_moral_character',
        'Año ng Pagkakakilanlan' => 'ano_ng_pagkakakilanlan',
        'ÑANDÚ Permit' => 'nandu_permit',
        'Sertipiko   ng    Barangay' => 'sertipiko_ng_barangay',
        'Cert. #12 (Special) & Co!' => 'cert_12_special_co',
        '123 Permit' => 'doc_123_permit',
        '  --Leading/Trailing--  ' => 'leading_trailing',
        'Café Señor' => 'cafe_senor',
        '!!!' => '',
    ];
    foreach ($derive as $name => $expected) {
        $got = document_type_derive_code($name);
        tc_check("derive code: " . json_encode($name, JSON_UNESCAPED_UNICODE) . " -> {$expected}", $got === $expected, $got);
    }
    $long = document_type_derive_code(str_repeat('Abcde fghij ', 9));
    tc_check('derive code: long name cut to 50 without a trailing underscore', strlen($long) <= 50 && !str_ends_with($long, '_') && preg_match(DOCUMENT_TYPE_CODE_PATTERN, $long) === 1, $long);

    // ------------------------------------------------------------ validation
    $bad = [
        'name missing' => [['name' => null], DOCUMENT_TYPE_MSG_NAME],
        'name not a string' => [['name' => 12345], DOCUMENT_TYPE_MSG_NAME],
        'name too short' => [['name' => 'Ab'], DOCUMENT_TYPE_MSG_NAME],
        'name only spaces around 2 chars' => [['name' => '   Ab   '], DOCUMENT_TYPE_MSG_NAME],
        'name 101 chars' => [['name' => str_repeat('a', 101)], DOCUMENT_TYPE_MSG_NAME],
        'name without a letter' => [['name' => '!!! 123'], DOCUMENT_TYPE_MSG_NAME],
        'name with a bell char' => [['name' => "Cert\x07ificate"], DOCUMENT_TYPE_MSG_NAME_CHARS],
        'name with a newline' => [['name' => "Cert\nificate"], DOCUMENT_TYPE_MSG_NAME_CHARS],
        'name with a bidi override' => [['name' => "Cert\u{202E}ificate"], DOCUMENT_TYPE_MSG_NAME_CHARS],
        'code with spaces/uppercase' => [['code' => 'Bad Code'], DOCUMENT_TYPE_MSG_CODE],
        'code starts with a digit' => [['code' => '1abc'], DOCUMENT_TYPE_MSG_CODE],
        'code too short' => [['code' => 'ab'], DOCUMENT_TYPE_MSG_CODE],
        'code 51 chars' => [['code' => 'a' . str_repeat('b', 50)], DOCUMENT_TYPE_MSG_CODE],
        'code not a string' => [['code' => 5], DOCUMENT_TYPE_MSG_CODE],
        'derived code too short (ÑÑ!)' => [['name' => 'ÑÑ!', 'code' => ''], DOCUMENT_TYPE_MSG_CODE],
        'fee negative' => [['fee' => -1], DOCUMENT_TYPE_MSG_FEE],
        'fee negative string' => [['fee' => '-1.00'], DOCUMENT_TYPE_MSG_FEE],
        'fee exponent string' => [['fee' => '1e3'], DOCUMENT_TYPE_MSG_FEE],
        'fee text' => [['fee' => 'abc'], DOCUMENT_TYPE_MSG_FEE],
        'fee 10000' => [['fee' => 10000], DOCUMENT_TYPE_MSG_FEE],
        'fee three decimals' => [['fee' => '1.234'], DOCUMENT_TYPE_MSG_FEE],
        'fee three decimals number' => [['fee' => 1.234], DOCUMENT_TYPE_MSG_FEE],
        'fee boolean' => [['fee' => true], DOCUMENT_TYPE_MSG_FEE],
        'fee missing' => [['fee' => null], DOCUMENT_TYPE_MSG_FEE],
        'days 61' => [['processing_days' => 61], DOCUMENT_TYPE_MSG_DAYS],
        'days -1' => [['processing_days' => -1], DOCUMENT_TYPE_MSG_DAYS],
        'days 1.5' => [['processing_days' => 1.5], DOCUMENT_TYPE_MSG_DAYS],
        'days "61"' => [['processing_days' => '61'], DOCUMENT_TYPE_MSG_DAYS],
        'days text' => [['processing_days' => 'x'], DOCUMENT_TYPE_MSG_DAYS],
        'days boolean' => [['processing_days' => true], DOCUMENT_TYPE_MSG_DAYS],
        'days missing' => [['processing_days' => null], DOCUMENT_TYPE_MSG_DAYS],
        'requirements 1001 chars' => [['requirements' => str_repeat('r', 1001)], DOCUMENT_TYPE_MSG_REQ_LENGTH],
        'requirements not a string' => [['requirements' => ['x']], DOCUMENT_TYPE_MSG_REQ_LENGTH],
        'requirements with NUL' => [['requirements' => "ID\x00"], DOCUMENT_TYPE_MSG_REQ_CHARS],
        'requirements with line separator' => [['requirements' => "ID\u{2028}x"], DOCUMENT_TYPE_MSG_REQ_CHARS],
        'field_keys a string' => [['field_keys' => 'full_name'], DOCUMENT_TYPE_MSG_FIELDS],
        'field_keys unknown key' => [['field_keys' => ['full_name', 'nope']], DOCUMENT_TYPE_MSG_FIELDS],
        'field_keys retired key' => [['field_keys' => ['official_position']], DOCUMENT_TYPE_MSG_FIELDS],
        'field_keys not strings' => [['field_keys' => [1]], DOCUMENT_TYPE_MSG_FIELDS],
        'field_keys an object' => [['field_keys' => ['a' => 'full_name']], DOCUMENT_TYPE_MSG_FIELDS],
        'field_keys too many' => [['field_keys' => array_fill(0, 15, 'full_name')], DOCUMENT_TYPE_MSG_FIELDS],
    ];
    foreach ($bad as $label => [$extra, $message]) {
        $p = tc_problem(static fn() => document_type_validate(tc_input($extra)));
        tc_check("validate 422: {$label}", tc_is($p, 422, $message), $p ? $p->status . ' ' . $p->getMessage() : 'no problem');
    }
    $clean = document_type_validate(tc_input(['name' => "  Certificate   of  Solo Parent ", 'requirements' => "  Valid ID\r\nBarangay clearance  "]));
    tc_check('validate: whitespace collapsed, code derived, fee/days normalized',
        $clean['name'] === 'Certificate of Solo Parent' && $clean['code'] === 'certificate_of_solo_parent' && $clean['fee'] === 25.0 && $clean['processing_days'] === 2);
    tc_check('validate: requirements keep newlines (CRLF -> LF), trimmed', $clean['requirements'] === "Valid ID\nBarangay clearance");
    tc_check('validate: system fields always added, catalogue order',
        $clean['field_keys'] === ['full_name', 'address', 'purpose', 'date_issued', 'reference_no', 'official_name', 'official_title'], json_encode($clean['field_keys']));
    $min = document_type_validate(tc_input(['field_keys' => [], 'requirements' => '', 'fee' => 0, 'processing_days' => '0', 'code' => 'my_code']));
    tc_check('validate: no optional fields -> only the four always-included ones', $min['field_keys'] === DOCUMENT_ALWAYS_FIELDS);
    tc_check('validate: empty requirements -> null; explicit code kept; zero fee and days ok',
        $min['requirements'] === null && $min['code'] === 'my_code' && $min['fee'] === 0.0 && $min['processing_days'] === 0);
    $dupe = document_type_validate(tc_input(['field_keys' => ['purpose', 'purpose', 'reference_no']]));
    tc_check('validate: duplicate keys collapse', $dupe['field_keys'] === ['purpose', 'date_issued', 'reference_no', 'official_name', 'official_title']);
    tc_check('validate: fee 9999.99 accepted', document_type_validate(tc_input(['fee' => 9999.99]))['fee'] === 9999.99);
    tc_check('validate: days 60 accepted', document_type_validate(tc_input(['processing_days' => 60]))['processing_days'] === 60);
    tc_check('validate: requirements of exactly 1000 chars accepted', mb_strlen((string)document_type_validate(tc_input(['requirements' => str_repeat('é', 1000)]))['requirements']) === 1000);
    $public = document_field_catalogue_public();
    $defaults = array_column(array_filter($public, static fn(array $f): bool => $f['default']), 'field_key');
    $always = array_column(array_filter($public, static fn(array $f): bool => $f['always']), 'field_key');
    tc_check('catalogue: default ticks are the four system fields + full_name, address, purpose',
        $defaults === ['full_name', 'address', 'purpose', 'date_issued', 'reference_no', 'official_name', 'official_title'], json_encode($defaults));
    tc_check('catalogue: always-included are exactly the four system fields', $always === ['date_issued', 'reference_no', 'official_name', 'official_title']);

    // ---------------------------------------------------- scratch database
    $admin = new mysqli($DB_HOST, $DB_USER, $DB_PASS);
    if ($admin->connect_error) throw new RuntimeException('MySQL connection refused: ' . $admin->connect_error);
    $admin->set_charset('utf8mb4');
    tc_check('scratch database differs from real database', $scratch !== $realDatabase && str_starts_with($scratch, $prefix));
    // Read-only guard counts on the real database; never a write.
    foreach (['document_types', 'document_type_fields', 'document_prices', 'document_template_versions', 'audit_log', 'document_requests'] as $table) {
        $realCounts[$table] = tc_count($admin, "SELECT COUNT(*) FROM `{$realDatabase}`.`{$table}`");
    }
    foreach (tc_database_names($admin, $prefix) as $old) tc_drop_database($admin, $old, $prefix);
    $schema = file_get_contents($root . '/database/database.sql');
    $migration = file_get_contents($migrationPath);
    if ($schema === false || $migration === false) throw new RuntimeException('schema or migration unavailable');
    if (!$admin->query("CREATE DATABASE `$scratch` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) throw new RuntimeException($admin->error);
    $scratchDb = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $scratch); $scratchDb->set_charset('utf8mb4');
    if ((string)tc_value($scratchDb, 'SELECT DATABASE()') !== $scratch) throw new RuntimeException('scratch database assertion failed');
    tc_check('scratch database prefix asserted before writes', str_starts_with((string)tc_value($scratchDb, 'SELECT DATABASE()'), $prefix));
    tc_import($scratchDb, $schema);

    $freshCreate = tc_create_table($scratchDb, 'document_types');
    tc_check('fresh install: document_types has code, processing_days, requirements, created_by, unique code and FK',
        str_contains($freshCreate, '`code` varchar(50)') && str_contains($freshCreate, '`processing_days` tinyint unsigned NOT NULL DEFAULT')
        && str_contains($freshCreate, '`requirements` varchar(1000)') && str_contains($freshCreate, '`created_by` int')
        && str_contains($freshCreate, 'UNIQUE KEY `document_type_code` (`code`)') && str_contains($freshCreate, 'CONSTRAINT `fk_document_types_created_by`'), $freshCreate);
    // Phase 7: resident types only; the internal Summons (code 'summons') is covered by complaint-summons.
    $seedCodes = tc_rows($scratchDb, "SELECT name, code FROM document_types WHERE audience = 'resident' ORDER BY sort_order");
    tc_check('fresh install: five seeded types carry their codes', array_column($seedCodes, 'code', 'name') === [
        'Barangay Clearance' => 'barangay_clearance', 'Certificate of Residency' => 'certificate_of_residency',
        'Certificate of Indigency' => 'certificate_of_indigency', 'Certificate of Good Moral Character' => 'certificate_of_good_moral_character',
        'Business Permit Endorsement' => 'business_permit_endorsement'], json_encode($seedCodes));

    // Catalogue mirrors every seeded field definition of the resident types (Phase 7:
    // the internal Summons fields are not offered for new types; see complaint-summons).
    $mismatch = [];
    foreach (tc_rows($scratchDb, "SELECT f.field_key, f.label, f.source, f.data_type, f.min_value, f.max_value, f.max_length, f.is_required FROM document_type_fields f JOIN document_types dt ON dt.id = f.document_type_id WHERE dt.audience = 'resident'") as $row) {
        $def = DOCUMENT_FIELD_CATALOGUE[$row['field_key']] ?? null;
        $norm = static fn($v) => $v === null ? null : (int)$v;
        if ($def === null || $def['label'] !== $row['label'] || $def['source'] !== $row['source'] || $def['data_type'] !== $row['data_type']
            || $def['min_value'] !== $norm($row['min_value']) || $def['max_value'] !== $norm($row['max_value'])
            || $def['max_length'] !== $norm($row['max_length']) || $def['is_required'] !== (int)$row['is_required']) {
            $mismatch[] = $row['field_key'];
        }
    }
    $seededKeys = array_column(tc_rows($scratchDb, "SELECT DISTINCT f.field_key FROM document_type_fields f JOIN document_types dt ON dt.id = f.document_type_id WHERE dt.audience = 'resident' ORDER BY f.field_key"), 'field_key');
    $catalogueKeys = array_keys(DOCUMENT_FIELD_CATALOGUE); sort($catalogueKeys);
    tc_check('catalogue: identical to the seeded field definitions', $mismatch === [], implode(',', array_unique($mismatch)));
    tc_check('catalogue: exactly the seeded key set (no extra, none missing)', $seededKeys === $catalogueKeys, json_encode($seededKeys));

    // ------------------------------------------- migration from a pre-5A table
    $strip = ['ALTER TABLE document_types DROP FOREIGN KEY fk_document_types_created_by',
        'ALTER TABLE document_types DROP INDEX fk_document_types_created_by',
        'ALTER TABLE document_types DROP INDEX document_type_code',
        'ALTER TABLE document_types DROP COLUMN code, DROP COLUMN processing_days, DROP COLUMN requirements, DROP COLUMN created_by'];
    foreach ($strip as $sql) if (!$scratchDb->query($sql) && !str_contains($sql, 'DROP INDEX fk_')) throw new RuntimeException($scratchDb->error . ' :: ' . $sql);
    tc_check('pre-5A state simulated (columns gone)', tc_count($scratchDb, "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='document_types' AND column_name IN ('code','processing_days','requirements','created_by')") === 0);
    $scratchDb->close(); $scratchDb = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $scratch); $scratchDb->set_charset('utf8mb4');
    tc_check('phase5a_ready is false before the migration', document_types_phase5a_ready($scratchDb) === false);
    $dataBefore = tc_rows($scratchDb, 'SELECT id, name, template_file, status, sort_order, created_at, updated_at FROM document_types ORDER BY id');
    tc_multi($scratchDb, $migration);
    $migratedCreate = tc_create_table($scratchDb, 'document_types');
    tc_check('migration: schema equals a fresh install', $migratedCreate === $freshCreate, $migratedCreate);
    tc_check('migration: seeded codes filled', array_column(tc_rows($scratchDb, "SELECT name, code FROM document_types WHERE audience = 'resident' ORDER BY sort_order"), 'code', 'name') === array_column($seedCodes, 'code', 'name'));
    tc_check('migration: existing rows otherwise untouched (incl. updated_at)',
        tc_rows($scratchDb, 'SELECT id, name, template_file, status, sort_order, created_at, updated_at FROM document_types ORDER BY id') === $dataBefore);
    $snapshotOnce = [tc_create_table($scratchDb, 'document_types'), tc_rows($scratchDb, 'SELECT * FROM document_types ORDER BY id')];
    tc_multi($scratchDb, $migration);
    $snapshotTwice = [tc_create_table($scratchDb, 'document_types'), tc_rows($scratchDb, 'SELECT * FROM document_types ORDER BY id')];
    tc_check('migration: second run changes nothing (schema and every row)', $snapshotOnce === $snapshotTwice);
    tc_multi($scratchDb, $migration);
    tc_check('migration: third run still equals a fresh install', tc_create_table($scratchDb, 'document_types') === $freshCreate);
    $scratchDb->close(); $scratchDb = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $scratch); $scratchDb->set_charset('utf8mb4');
    tc_check('phase5a_ready is true after the migration', document_types_phase5a_ready($scratchDb) === true);

    // ------------------------------------------------------ officials
    $password = 'TypeCreate-test-2026!';
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $addOfficial = static function (string $username, string $role, string $contact) use ($scratchDb, $hash): int {
        $stmt = $scratchDb->prepare("INSERT INTO barangay_officials (fname,lname,username,role,address,contact,password,position,status) VALUES ('Scratch',?,?,?,'Purok 1',?,?,'Tester','active')");
        $lname = ucfirst($role); $stmt->bind_param('sssss', $lname, $username, $role, $contact, $hash); $stmt->execute(); $id = (int)$stmt->insert_id; $stmt->close();
        if ($id <= 0) throw new RuntimeException('scratch official insert failed');
        return $id;
    };
    $staffId = $addOfficial('typecreate-staff', 'staff', '+639171110071');
    $adminId = $addOfficial('typecreate-admin', 'admin', '+639171110072');

    // ------------------------------------------------- library: create
    $auditBefore = tc_count($scratchDb, 'SELECT COUNT(*) FROM audit_log');
    $created = document_type_create($scratchDb, tc_input(['name' => 'Certificate of Solo Parent']), $staffId, 'Scratch Staff');
    $typeId = $created['type_id'];
    $row = tc_rows($scratchDb, "SELECT * FROM document_types WHERE id={$typeId}")[0] ?? [];
    tc_check('create: returns type_id and derived code', $typeId > 0 && $created['code'] === 'certificate_of_solo_parent');
    tc_check('create: row is active, no template file, next sort order, details stored, created_by from session',
        ($row['status'] ?? '') === 'active' && ($row['template_file'] ?? null) === '' && (int)$row['sort_order'] === 6
        && (int)$row['processing_days'] === 2 && $row['requirements'] === "Valid ID\nBarangay clearance" && (int)$row['created_by'] === $staffId, json_encode($row));
    $fields = tc_rows($scratchDb, "SELECT field_key, label, source, data_type, min_value, max_value, max_length, is_required, sort_order FROM document_type_fields WHERE document_type_id={$typeId} ORDER BY sort_order");
    $expectedKeys = ['full_name', 'address', 'purpose', 'date_issued', 'reference_no', 'official_name', 'official_title'];
    $fieldOk = array_column($fields, 'field_key') === $expectedKeys && array_column($fields, 'sort_order') === array_map('strval', range(1, 7));
    foreach ($fields as $f) {
        $def = DOCUMENT_FIELD_CATALOGUE[$f['field_key']];
        $fieldOk = $fieldOk && $f['label'] === $def['label'] && $f['source'] === $def['source'] && (int)$f['is_required'] === $def['is_required'];
    }
    tc_check('create: field rows copied from the catalogue in order', $fieldOk, json_encode($fields));
    tc_check('create: price row written with the fee', (string)tc_value($scratchDb, "SELECT unit_price FROM document_prices WHERE document_type='Certificate of Solo Parent'") === '25.00');
    $audit = tc_rows($scratchDb, "SELECT official_id, action, target_type, target_reference, details FROM audit_log WHERE action='Created document type'");
    tc_check('create: exactly one audit row, name and code only',
        tc_count($scratchDb, 'SELECT COUNT(*) FROM audit_log') === $auditBefore + 1 && count($audit) === 1
        && (int)$audit[0]['official_id'] === $staffId && $audit[0]['target_type'] === 'document_type' && $audit[0]['target_reference'] === (string)$typeId
        && $audit[0]['details'] === 'Name: Certificate of Solo Parent; Code: certificate_of_solo_parent', json_encode($audit));
    tc_check('create: audit has no fee, requirements or field list', !str_contains((string)($audit[0]['details'] ?? ''), '25') && !str_contains((string)($audit[0]['details'] ?? ''), 'Valid ID')
        && !str_contains((string)($audit[0]['details'] ?? ''), 'full_name'));
    tc_check('visibility: new type without an active version is not requestable', document_registry_requestable($scratchDb, 'Certificate of Solo Parent') === null);
    tc_check('visibility: seeded type with an active version is requestable', document_registry_requestable($scratchDb, 'Barangay Clearance') !== null);

    // -------------------------------------------------------- library: 409
    $count = static fn(): int => tc_count($scratchDb, 'SELECT COUNT(*) FROM document_types');
    $prices = static fn(): int => tc_count($scratchDb, 'SELECT COUNT(*) FROM document_prices');
    $types0 = $count(); $prices0 = $prices();
    $p = tc_problem(static fn() => document_type_create($scratchDb, tc_input(['name' => 'certificate OF solo PARENT', 'code' => 'other_code']), $staffId, 'x'));
    tc_check('409: case-insensitive name clash', tc_is($p, 409, DOCUMENT_TYPE_MSG_NAME_TAKEN));
    $p = tc_problem(static fn() => document_type_create($scratchDb, tc_input(['name' => '  Certificate   of Solo Parent  ', 'code' => 'other_code']), $staffId, 'x'));
    tc_check('409: name clash after whitespace collapse', tc_is($p, 409, DOCUMENT_TYPE_MSG_NAME_TAKEN));
    $p = tc_problem(static fn() => document_type_create($scratchDb, tc_input(['name' => 'Different Name', 'code' => 'certificate_of_solo_parent']), $staffId, 'x'));
    tc_check('409: code clash', tc_is($p, 409, DOCUMENT_TYPE_MSG_CODE_TAKEN));
    $p = tc_problem(static fn() => document_type_create($scratchDb, tc_input(['name' => 'Barangay Clearance Copy', 'code' => 'barangay_clearance']), $staffId, 'x'));
    tc_check('409: code clash with a seeded type', tc_is($p, 409, DOCUMENT_TYPE_MSG_CODE_TAKEN));
    $scratchDb->query("UPDATE document_types SET status='archived' WHERE name='Certificate of Good Moral Character'");
    $p = tc_problem(static fn() => document_type_create($scratchDb, tc_input(['name' => 'CERTIFICATE OF GOOD MORAL CHARACTER', 'code' => 'gmc_again']), $staffId, 'x'));
    tc_check('409: name clash with an archived type', tc_is($p, 409, DOCUMENT_TYPE_MSG_NAME_TAKEN));
    $scratchDb->query("UPDATE document_types SET status='active' WHERE name='Certificate of Good Moral Character'");
    $scratchDb->query("INSERT INTO document_prices (document_type, unit_price) VALUES ('Orphan Price Type', 77.00)");
    $p = tc_problem(static fn() => document_type_create($scratchDb, tc_input(['name' => 'orphan price type', 'fee' => '1.00']), $staffId, 'x'));
    tc_check('409: orphan price row with the same name', tc_is($p, 409, DOCUMENT_TYPE_MSG_NAME_TAKEN));
    tc_check('409: orphan price not overwritten', (string)tc_value($scratchDb, "SELECT unit_price FROM document_prices WHERE document_type='Orphan Price Type'") === '77.00');
    tc_check('409s wrote nothing', $count() === $types0 && $prices() === $prices0 + 1);

    // Pre-SELECT bypassed: the unique keys alone must stop duplicates.
    $scratchDb->query("INSERT INTO document_types (name, code, template_file, status, sort_order) VALUES ('Bypass Test Type', 'bypass_direct', '', 'active', 90)");
    $types1 = $count(); $prices1 = $prices(); $fields1 = tc_count($scratchDb, 'SELECT COUNT(*) FROM document_type_fields');
    $p = tc_problem(static fn() => document_type_insert($scratchDb, document_type_validate(tc_input(['name' => 'BYPASS test TYPE', 'code' => 'bypass_other'])), $staffId));
    tc_check('unique key: name clash with different case (no pre-check) -> 409', tc_is($p, 409, DOCUMENT_TYPE_MSG_NAME_TAKEN));
    $p = tc_problem(static fn() => document_type_insert($scratchDb, document_type_validate(tc_input(['name' => 'Fresh Bypass Name', 'code' => 'bypass_direct'])), $staffId));
    tc_check('unique key: code clash (no pre-check) -> 409', tc_is($p, 409, DOCUMENT_TYPE_MSG_CODE_TAKEN));
    $scratchDb->query("INSERT INTO document_prices (document_type, unit_price) VALUES ('Orphan Bypass', 5.00)");
    $p = tc_problem(static fn() => document_type_insert($scratchDb, document_type_validate(tc_input(['name' => 'orphan BYPASS', 'code' => 'orphan_bypass'])), $staffId));
    tc_check('unique key: orphan price clash (no pre-check) -> 409 and the type insert rolled back',
        tc_is($p, 409, DOCUMENT_TYPE_MSG_NAME_TAKEN) && tc_count($scratchDb, "SELECT COUNT(*) FROM document_types WHERE code='orphan_bypass'") === 0
        && (string)tc_value($scratchDb, "SELECT unit_price FROM document_prices WHERE document_type='Orphan Bypass'") === '5.00');
    tc_check('unique key refusals left no partial rows', $count() === $types1 && $prices() === $prices1 + 1 && tc_count($scratchDb, 'SELECT COUNT(*) FROM document_type_fields') === $fields1);

    // ---------------------------------------------- race: two processes
    $child = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typecreate-child-' . getmypid() . '.php'; $tempFiles[] = $child;
    $go = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typecreate-go-' . getmypid(); $tempFiles[] = $go;
    file_put_contents($child, '<?php
foreach (["GEMINI_API_KEY", "GEMINI_MODEL", "HTTPSMS_API_KEY", "HTTPSMS_FROM_NUMBER"] as $k) { putenv($k . "="); $_ENV[$k] = ""; $_SERVER[$k] = ""; }
if (PHP_SAPI !== "cli" || !str_starts_with((string)($argv[1] ?? ""), "capstone_typecreate_")) exit(3);
require ' . var_export($root . '/api/common.php', true) . ';
require ' . var_export($root . '/api/documents/_type_create.php', true) . ';
mysqli_report(MYSQLI_REPORT_OFF);
$db = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $argv[1]); $db->set_charset("utf8mb4");
if ((string)$db->query("SELECT DATABASE()")->fetch_row()[0] !== $argv[1]) exit(4);
try {
    $clean = document_type_validate(json_decode($argv[3], true));
    document_type_precheck($db, $clean);          // both pass: nothing is inserted yet
    echo "READY\n"; fflush(STDOUT);
    for ($i = 0; $i < 400 && !is_file($argv[2]); $i++) usleep(10000);
    echo json_encode(document_type_insert($db, $clean, (int)$argv[4])), "\n";
} catch (Throwable $e) { echo "ERROR " . get_class($e) . ($e instanceof RequestProblem ? " " . $e->status . " " . $e->getMessage() : "") . "\n"; exit(1); }
');
    $spawn = static function (string $body) use ($child, $scratch, $go, $staffId): array {
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', $child, $scratch, $go, $body, (string)$staffId],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('child process failed to start');
        fclose($pipes[0]);
        return [$process, $pipes];
    };
    $waitReady = static function (array $handle): string {
        $line = (string)fgets($handle[1][1]);
        return trim($line);
    };
    $collect = static function (array $handle): array {
        [$process, $pipes] = $handle;
        $out = (string)stream_get_contents($pipes[1]); $err = (string)stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        return [trim($out), $err, proc_close($process)];
    };
    @unlink($go);
    $a = $spawn((string)json_encode(tc_input(['name' => 'Race Certificate', 'code' => 'race_a']), JSON_UNESCAPED_UNICODE));
    $b = $spawn((string)json_encode(tc_input(['name' => 'race certificate', 'code' => 'race_b']), JSON_UNESCAPED_UNICODE));
    $readyA = $waitReady($a); $readyB = $waitReady($b);
    tc_check('race: both processes passed the friendly pre-check', $readyA === 'READY' && $readyB === 'READY', $readyA . '|' . $readyB);
    touch($go);
    [$outA, $errA, $codeA] = $collect($a); [$outB, $errB, $codeB] = $collect($b);
    $wins = (int)($codeA === 0) + (int)($codeB === 0);
    $loser = $codeA === 0 ? $outB : $outA;
    tc_check('race: exactly one process created the type', $wins === 1, "A={$codeA} {$outA} {$errA} | B={$codeB} {$outB} {$errB}");
    tc_check('race: the other got 409 from the unique key', str_contains($loser, 'ERROR RequestProblem 409 ' . DOCUMENT_TYPE_MSG_NAME_TAKEN), $loser);
    tc_check('race: one type row, one price row, fields for that row only',
        tc_count($scratchDb, "SELECT COUNT(*) FROM document_types WHERE name='Race Certificate'") === 1
        && tc_count($scratchDb, "SELECT COUNT(*) FROM document_prices WHERE document_type='Race Certificate'") === 1
        && tc_count($scratchDb, "SELECT COUNT(*) FROM document_type_fields f LEFT JOIN document_types t ON t.id=f.document_type_id WHERE t.id IS NULL") === 0);

    // ------------------------------------------------------------ endpoints
    $hh = $scratchDb->query("INSERT INTO households (household_code,address,household_size) VALUES ('TC-" . getmypid() . "','Purok 1',1)");
    $householdId = (int)$scratchDb->insert_id;
    $residentPassword = 'TypeCreate-resident-2026!';
    $residentHash = password_hash($residentPassword, PASSWORD_DEFAULT);
    $stmt = $scratchDb->prepare("INSERT INTO residents (fname,lname,username,address,contact,password,status,household_id) VALUES ('Scratch','Resident','typecreate-resident','Purok 1','+639171110073',?,'active',?)");
    $stmt->bind_param('si', $residentHash, $householdId); $stmt->execute(); $residentId = (int)$stmt->insert_id; $stmt->close();
    $scratchDb->query("UPDATE households SET head_resident_id={$residentId} WHERE id={$householdId}");

    if (!mkdir($sessionPath, 0700, true) && !is_dir($sessionPath)) throw new RuntimeException('session directory failed');
    file_put_contents($prepend, "<?php foreach (['GEMINI_API_KEY', 'GEMINI_MODEL', 'HTTPSMS_API_KEY', 'HTTPSMS_FROM_NUMBER'] as \$k) { putenv(\$k . '='); \$_ENV[\$k] = ''; \$_SERVER[\$k] = ''; }\n"
        . "if (!str_starts_with((string)getenv('BARANGAY_DB_NAME'), 'capstone_typecreate_')) { http_response_code(500); exit; }\n"
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
        if ($base === null) { tc_stop_server($server); $server = null; }
    }
    if ($base === null) throw new RuntimeException('Endpoint server did not start.');
    $login = static function (string $action, string $username, string $pass) use ($base): string {
        $r = tc_http($base . '/pages/login.php', 'POST', ['action' => $action, 'username' => $username, 'password' => $pass]);
        return $r['status'] === 302 ? $r['cookie'] : '';
    };
    $staff = $login('official-login', 'typecreate-staff', $password);
    $adminCookie = $login('official-login', 'typecreate-admin', $password);
    $resident = $login('resident-login', 'typecreate-resident', $residentPassword);
    tc_check('endpoint: staff, admin and resident logins on the scratch database', $staff !== '' && $adminCookie !== '' && $resident !== '');

    $createUrl = $base . '/api/documents/type_create.php';
    $json = static fn(array $extra = []): string => (string)json_encode(tc_input($extra), JSON_UNESCAPED_UNICODE);
    tc_check('endpoint create: no session -> 401', tc_http($createUrl, 'POST', $json())['status'] === 401);
    tc_check('endpoint create: resident session -> 401', tc_http($createUrl, 'POST', $json(['name' => 'Resident Attempt']), $resident)['status'] === 401);
    tc_check('endpoint create: GET -> 405', tc_http($createUrl, 'GET', null, $staff)['status'] === 405);
    $r = tc_http($createUrl, 'POST', '{not json', $staff);
    tc_check('endpoint create: invalid JSON -> 422 name message', $r['status'] === 422 && ($r['json']['message'] ?? '') === DOCUMENT_TYPE_MSG_NAME);
    foreach (['name' => [['name' => 'Ab'], DOCUMENT_TYPE_MSG_NAME], 'code' => [['code' => 'Bad Code'], DOCUMENT_TYPE_MSG_CODE],
              'fee' => [['fee' => -5], DOCUMENT_TYPE_MSG_FEE], 'days' => [['processing_days' => 61], DOCUMENT_TYPE_MSG_DAYS],
              'requirements' => [['requirements' => str_repeat('x', 1001)], DOCUMENT_TYPE_MSG_REQ_LENGTH],
              'requirements chars' => [['requirements' => "a\x01b"], DOCUMENT_TYPE_MSG_REQ_CHARS],
              'field_keys' => [['field_keys' => ['nope']], DOCUMENT_TYPE_MSG_FIELDS]] as $label => [$extra, $message]) {
        $r = tc_http($createUrl, 'POST', $json(array_merge(['name' => 'Endpoint Invalid'], $extra)), $staff);
        tc_check("endpoint create: {$label} -> 422 exact message", $r['status'] === 422 && ($r['json']['message'] ?? '') === $message, $r['body']);
    }
    $r = tc_http($createUrl, 'POST', $json(['name' => 'Certificate of First-Time Jobseeker', 'code' => '', 'fee' => '0.00', 'processing_days' => 1,
        'requirements' => 'Valid ID', 'field_keys' => ['full_name', 'address', 'purpose']]), $staff);
    $newId = (int)($r['json']['type_id'] ?? 0);
    tc_check('endpoint create: staff (any role) -> 200 {type_id, code} only', $r['status'] === 200 && $newId > 0
        && array_keys($r['json']) === ['success', 'type_id', 'code'] && $r['json']['code'] === 'certificate_of_first_time_jobseeker', $r['body']);
    tc_check('endpoint create: no file path in the response', !str_contains($r['body'], '.docx') && !str_contains($r['body'], 'document_templates'));
    $r = tc_http($createUrl, 'POST', $json(['name' => 'CERTIFICATE OF FIRST-TIME JOBSEEKER', 'code' => 'jobseeker_two']), $staff);
    tc_check('endpoint create: different case -> 409 exact message', $r['status'] === 409 && ($r['json']['message'] ?? '') === DOCUMENT_TYPE_MSG_NAME_TAKEN);
    $scratchDb->query("INSERT INTO document_types (name, code, template_file, status, sort_order) VALUES ('Direct Insert Type', 'direct_insert', '', 'active', 91)");
    $r = tc_http($createUrl, 'POST', $json(['name' => 'direct INSERT type', 'code' => 'direct_two']), $staff);
    tc_check('endpoint create: name inserted directly, then API with different case -> 409', $r['status'] === 409 && ($r['json']['message'] ?? '') === DOCUMENT_TYPE_MSG_NAME_TAKEN);
    $r = tc_http($createUrl, 'POST', $json(['name' => 'Another Unique Name', 'code' => 'certificate_of_first_time_jobseeker']), $staff);
    tc_check('endpoint create: code clash -> 409 exact message', $r['status'] === 409 && ($r['json']['message'] ?? '') === DOCUMENT_TYPE_MSG_CODE_TAKEN);
    $r = tc_http($createUrl, 'POST', $json(['name' => 'Orphan Price Type', 'code' => 'orphan_api']), $adminCookie);
    tc_check('endpoint create: orphan price row -> 409 (admin too)', $r['status'] === 409 && ($r['json']['message'] ?? '') === DOCUMENT_TYPE_MSG_NAME_TAKEN);
    $auditRow = tc_rows($scratchDb, "SELECT official_id, details FROM audit_log WHERE action='Created document type' AND target_reference='{$newId}'");
    tc_check('endpoint create: audit row from the session official, name and code only', count($auditRow) === 1 && (int)$auditRow[0]['official_id'] === $staffId
        && $auditRow[0]['details'] === 'Name: Certificate of First-Time Jobseeker; Code: certificate_of_first_time_jobseeker');

    $list = tc_http($base . '/api/documents/types_list.php', 'GET', null, $staff);
    $listed = array_values(array_filter($list['json']['types'] ?? [], static fn(array $t): bool => (int)$t['id'] === $newId))[0] ?? [];
    tc_check('types_list: catalogue and can_create included', count($list['json']['catalogue'] ?? []) === count(DOCUMENT_FIELD_CATALOGUE) && ($list['json']['can_create'] ?? false) === true);
    tc_check('types_list: new type with code, processing days, no active version', ($listed['code'] ?? '') === 'certificate_of_first_time_jobseeker'
        && ($listed['processing_days'] ?? null) === 1 && array_key_exists('active_version_id', $listed) && $listed['active_version_id'] === null
        && ($listed['versions'] ?? null) === [], json_encode($listed));

    $reqUrl = $base . '/api/documents/requestable_types.php';
    tc_check('requestable_types: no session -> 401', tc_http($reqUrl)['status'] === 401);
    tc_check('requestable_types: POST -> 405', tc_http($reqUrl, 'POST', '{}', $resident)['status'] === 405);
    $names = static fn(string $cookie): array => array_column(tc_http($reqUrl, 'GET', null, $cookie)['json']['types'] ?? [], 'name');
    $r = tc_http($reqUrl, 'GET', null, $resident);
    tc_check('requestable_types: resident sees the five seeded types only (new types hidden)', array_column($r['json']['types'] ?? [], 'name') === [
        'Barangay Clearance', 'Certificate of Residency', 'Certificate of Indigency', 'Certificate of Good Moral Character', 'Business Permit Endorsement'], $r['body']);
    tc_check('requestable_types: only name, fee, processing_days, requirements per row',
        array_keys($r['json']) === ['success', 'types'] && array_keys($r['json']['types'][0] ?? []) === ['name', 'fee', 'processing_days', 'requirements'] && !str_contains($r['body'], '"id"'));
    tc_check('requestable_types: official session also allowed', in_array('Barangay Clearance', $names($staff), true));

    $requestUrl = $base . '/api/requests/create.php';
    $request = static fn(string $type): string => (string)json_encode(['document_type' => $type, 'purpose' => 'Employment', 'copies' => 1]);
    $requests0 = tc_count($scratchDb, 'SELECT COUNT(*) FROM document_requests');
    $r = tc_http($requestUrl, 'POST', $request('Certificate of First-Time Jobseeker'), $resident);
    tc_check('request API: type with no active version -> 422 "This document is not available yet."', $r['status'] === 422 && ($r['json']['message'] ?? '') === 'This document is not available yet.', $r['body']);
    $scratchDb->query("UPDATE document_types SET status='archived' WHERE id={$newId}");
    $r = tc_http($requestUrl, 'POST', $request('Certificate of First-Time Jobseeker'), $resident);
    tc_check('request API: archived wins over no-active-version (message unchanged)', $r['status'] === 422 && ($r['json']['message'] ?? '') === 'This document type is no longer available.');
    $scratchDb->query("UPDATE document_types SET status='active' WHERE id={$newId}");
    $r = tc_http($requestUrl, 'POST', $request('No Such Document'), $resident);
    tc_check('request API: unknown type keeps the existing message', $r['status'] === 422 && ($r['json']['message'] ?? '') === 'This document type is no longer available.');
    tc_check('request API: refusals stored no request', tc_count($scratchDb, 'SELECT COUNT(*) FROM document_requests') === $requests0);
    $r = tc_http($requestUrl, 'POST', $request('Barangay Clearance'), $resident);
    tc_check('request API: seeded type still accepted', $r['status'] === 200 && ($r['json']['success'] ?? false) === true, $r['body']);

    // Full lifecycle: v1 draft upload -> test render -> activate -> visible.
    $v1 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'typecreate-v1-' . getmypid() . '.docx'; $tempFiles[] = $v1;
    copy($root . '/document_templates/certificate_of_indigency.docx', $v1);
    $ch = curl_init($base . '/api/documents/template_upload.php');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Cookie: ' . $staff],
        CURLOPT_POSTFIELDS => ['document_type_id' => (string)$newId, 'notes' => 'First version', 'file' => new CURLFile($v1, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'jobseeker.docx')]]);
    $uploadBody = (string)curl_exec($ch); $uploadStatus = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    $upload = json_decode($uploadBody, true) ?: [];
    $versionId = (int)($upload['version_id'] ?? 0);
    if ($versionId > 0) $templateFiles[] = (string)tc_value($scratchDb, "SELECT file_name FROM document_template_versions WHERE id={$versionId}");
    tc_check('lifecycle: first upload saved as v1 draft and the check passes', $uploadStatus === 200 && ($upload['check_ok'] ?? false) === true
        && tc_count($scratchDb, "SELECT COUNT(*) FROM document_template_versions WHERE id={$versionId} AND version_no=1 AND status='draft'") === 1, $uploadBody);
    tc_check('lifecycle: draft alone does not publish', !in_array('Certificate of First-Time Jobseeker', $names($resident), true));
    $r = tc_http($base . '/api/documents/template_activate.php', 'POST', (string)json_encode(['version_id' => $versionId, 'expected_active_version_id' => 0]), $staff);
    tc_check('lifecycle: activation without a test render refused (4A rule unchanged)', $r['status'] === 422);
    $r = tc_http($base . '/api/documents/template_test_render.php', 'POST', (string)json_encode(['version_id' => $versionId]), $staff);
    $token = (string)($r['json']['preview_token'] ?? '');
    if (preg_match('/^[a-f0-9]{32}$/', $token)) $storage->track($storage->previews . DIRECTORY_SEPARATOR . $token . '.pdf');
    tc_check('lifecycle: test render of v1 succeeds (temp storage)', $r['status'] === 200 && $token !== '', $r['body']);
    $r = tc_http($base . '/api/documents/template_activate.php', 'POST', (string)json_encode(['version_id' => $versionId, 'expected_active_version_id' => 0]), $staff);
    tc_check('lifecycle: v1 activated', $r['status'] === 200, $r['body']);
    $r = tc_http($reqUrl, 'GET', null, $resident);
    $row = array_values(array_filter($r['json']['types'] ?? [], static fn(array $t): bool => $t['name'] === 'Certificate of First-Time Jobseeker'))[0] ?? null;
    tc_check('lifecycle: now visible to residents with fee, processing days and requirements',
        $row !== null && is_numeric($row['fee']) && (float)$row['fee'] === 0.0 && $row['processing_days'] === 1 && $row['requirements'] === 'Valid ID', json_encode($row));
    $r = tc_http($requestUrl, 'POST', $request('Certificate of First-Time Jobseeker'), $resident);
    tc_check('lifecycle: resident request accepted after activation (fee 0 -> waived)', $r['status'] === 200
        && (string)tc_value($scratchDb, "SELECT payment_status FROM document_requests WHERE document_type='Certificate of First-Time Jobseeker'") === 'waived', $r['body']);
    tc_check('lifecycle: archive stays admin-only', tc_http($base . '/api/documents/type_archive.php', 'POST', (string)json_encode(['document_type_id' => $newId, 'archived' => true]), $staff)['status'] === 403);
    $r = tc_http($base . '/api/documents/type_archive.php', 'POST', (string)json_encode(['document_type_id' => $newId, 'archived' => true]), $adminCookie);
    tc_check('lifecycle: admin archives the new type', $r['status'] === 200);
    tc_check('lifecycle: archived type hidden from residents', !in_array('Certificate of First-Time Jobseeker', $names($resident), true));
    $r = tc_http($requestUrl, 'POST', $request('Certificate of First-Time Jobseeker'), $resident);
    tc_check('lifecycle: archived type refused by the request API', $r['status'] === 422 && ($r['json']['message'] ?? '') === 'This document type is no longer available.');
    tc_check('lifecycle: archived, never deleted (row, fields, versions kept)', tc_count($scratchDb, "SELECT COUNT(*) FROM document_types WHERE id={$newId} AND status='archived'") === 1
        && tc_count($scratchDb, "SELECT COUNT(*) FROM document_type_fields WHERE document_type_id={$newId}") === 7
        && tc_count($scratchDb, "SELECT COUNT(*) FROM document_template_versions WHERE document_type_id={$newId}") === 1);
    tc_stop_server($server); $server = null;
} catch (Throwable $e) {
    tc_check('suite completed without an unexpected error', false, get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $cleanup();
    if ($scratchDb instanceof mysqli) { @$scratchDb->close(); $scratchDb = null; }
    tc_check('template storage back to its starting files', tc_snapshot($templateRoot) === $templateSnapshot,
        json_encode(array_keys(array_diff_key(tc_snapshot($templateRoot), $templateSnapshot))));
    if ($admin instanceof mysqli) {
        foreach ($realCounts as $table => $count) {
            tc_check('real row count unchanged ' . $table, tc_count($admin, "SELECT COUNT(*) FROM `{$realDatabase}`.`{$table}`") === $count);
        }
        tc_check('no scratch database left behind', tc_database_names($admin, $prefix) === []);
    }
}
echo "Results: {$passed}/" . ($passed + $failed) . " passed.\n";
exit($failed === 0 ? 0 : 1);
